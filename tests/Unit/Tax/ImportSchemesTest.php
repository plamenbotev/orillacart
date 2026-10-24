<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveImportRegistration;
use OrillaCart\Tax\Application\Dto\SaveImportSchemes;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Low-value goods shipped into a country with an import scheme: taxed there at checkout when the shop is
 * registered and they are worth no more than the limit, otherwise untaxed, the customer paying at the border.
 */
final class ImportSchemesTest extends TestCase
{
    private TestShop $shop;
    private CartOwner $owner;
    private int $vat;

    protected function setUp(): void
    {
        // A shop in the US, with Germany's and the UK's rates.
        $this->shop = new TestShop();
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'US', 'NY'));
        $this->vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($this->vat, new SaveTaxRate('DE', null, '19', 'MwSt'));
        $this->shop->taxGroups->addRate($this->vat, new SaveTaxRate('GB', null, '20', 'VAT'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $this->vat));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('100', weight: '1'));
        $this->shop->content->add(11, 'E-book');
        $this->shop->products->save(11, new SaveProduct('10', type: 'virtual'));
        $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Everywhere', '10')]));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
    }

    public function testWithoutASchemeListedNothingChanges(): void
    {
        self::assertSame([['MwSt', '19', '1.9']], $this->taxes('DE'));
        self::assertNull($this->summary('DE')->importScheme);
    }

    public function testRegisteredGoodsUpToTheLimitAreTaxedAtTheDestination(): void
    {
        $this->schemes(new SaveImportRegistration('ioss', 'im 276 000 0001', '150'));

        self::assertSame([['MwSt', '19', '1.9']], $this->taxes('DE'), 'the goods and their shipping');
        self::assertSame(['ioss', false], [$this->summary('DE')->importScheme, $this->summary('DE')->importCharges]);

        $placed = $this->shop->checkout->placeOrder($this->owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Unter den Linden', 'Berlin', '10117', 'DE', '+49 30 000000'), shippingOption: $this->summary('DE')->shippingOption, payment: 'bank_transfer'));
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['ioss', 'IM2760000001'], [$order->importScheme, $order->importNumber]);
        $html = $this->shop->templates->render('invoices/document.html.twig', ['document' => ['invoice' => ['number' => 'INV-1', 'kind' => 'invoice', 'amount' => $order->total, 'currency' => $order->currency, 'issued_at' => $order->placedAt], 'seller' => ['legal_name' => 'Shop', 'address' => '', 'vat_number' => '', 'company_number' => '', 'bank_details' => '', 'footer' => ''], 'order' => $order, 'lines' => [], 'taxes' => [], 'corrects' => null]]);
        self::assertStringContainsString('IOSS number: IM2760000001', $html);
    }

    public function testGoodsOverTheLimitGoAsExportsTheCustomerPayingAtTheBorder(): void
    {
        $this->schemes(new SaveImportRegistration('ioss', 'IM2760000001', '150'));
        $this->shop->carts->add($this->owner, new AddToCart(10));

        self::assertSame([], $this->taxes('DE'), '200 is over 150');
        $summary = $this->summary('DE');
        self::assertSame(['ioss', true], [$summary->importScheme, $summary->importCharges]);
        self::assertStringContainsString('import VAT and duties may be due on delivery', $this->shop->templates->render('storefront/_checkout_summary.html.twig', ['summary' => $summary, 'address_known' => true]));
    }

    public function testAFeeGoesAsTheGoodsDoUnderAScheme(): void
    {
        $this->schemes(new SaveImportRegistration('ioss', 'IM2760000001', '150'));
        $this->shop->carts->add($this->owner, new AddToCart(10));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true, fee: '2', feeLabel: 'Handling'));

        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails('DE', payment: 'bank_transfer'));

        self::assertSame(['2', '0'], [$summary->fees[0]->amount ?? null, $summary->feesTax], 'an export: no German VAT on the fee either');
    }

    public function testWithoutANumberTheGoodsGoAsExportsButServicesAreTaxedAsBefore(): void
    {
        $this->schemes(new SaveImportRegistration('ioss', null, '150'));
        $this->shop->carts->add($this->owner, new AddToCart(11));

        // The e-book is no import: taxed as the shop's settings say; the lamp and its shipping are not.
        self::assertSame([['MwSt', '19', '0']], $this->taxes('DE'));
        self::assertSame(['1.9', '0'], [$this->summary('DE')->itemsTax, $this->summary('DE')->shippingTax]);
        self::assertTrue($this->summary('DE')->importCharges);
    }

    public function testAShopInTheEuChargesTheUksVatUpToItsLimitWhateverItsBasis(): void
    {
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'DE'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::EuDestination, $this->vat));
        self::assertSame([], $this->taxes('GB'), 'outside the EU: no tax');

        $this->schemes(new SaveImportRegistration('uk', 'GB123456789', '135'), new SaveImportRegistration('ioss', 'IM2760000001', '150'));
        self::assertSame([['VAT', '20', '2']], $this->taxes('GB'));
        self::assertNull($this->summary('DE')->importScheme, 'IOSS is not for a shop inside the EU');
    }

    public function testTheUksLimitIsForTheWholeConsignment(): void
    {
        $this->schemes(new SaveImportRegistration('uk', 'GB123456789', '135'));
        self::assertSame([['VAT', '20', '2']], $this->taxes('GB'), '100');

        $this->shop->carts->add($this->owner, new AddToCart(10));
        self::assertSame([], $this->taxes('GB'), 'two lamps: 200');
        self::assertTrue($this->summary('GB')->importCharges);
    }

    public function testNumbersAndLimitsAreChecked(): void
    {
        foreach ([
            [new SaveImportRegistration('ioss', 'GB123456789', '150')],
            [new SaveImportRegistration('uk', 'GB1234', '135')],
            [new SaveImportRegistration('voec', '2024123', '0')],
            [new SaveImportRegistration('voec', '2024123', '3000'), new SaveImportRegistration('voec', null, '3000')],
        ] as $index => $refused) {
            try {
                $this->schemes(...$refused);
                self::fail("case {$index} was taken");
            } catch (RuleViolation) {
            }
        }
        self::assertSame([], $this->shop->importSchemes->current()->schemes);
        self::assertSame(['voec', '2024123', '3000'], array_values($this->schemes(new SaveImportRegistration('voec', '2024-123', '3000'))[0]));
    }

    /** @return list<array{scheme: string, number: ?string, limit: string}> */
    private function schemes(SaveImportRegistration ...$registrations): array
    {
        return $this->shop->importSchemes->save(new SaveImportSchemes(array_values($registrations)))->schemes;
    }

    private function summary(string $country): \OrillaCart\Checkout\Application\Dto\CheckoutView
    {
        return $this->shop->checkout->summary($this->owner, new CheckoutDetails($country));
    }

    /** @return list<array{string, string, string}> each tax with its rate and what it charges on shipping */
    private function taxes(string $country): array
    {
        return array_map(static fn($tax): array => [$tax->name, $tax->percent, $tax->shipping], $this->summary($country)->taxes);
    }
}
