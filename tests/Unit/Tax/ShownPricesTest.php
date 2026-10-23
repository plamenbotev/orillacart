<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Customers\Application\AddressBook;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxExemption;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Prices shown with tax or without, whichever way they are entered; and prices entered with tax for customers
 * taxed at another rate: the same price everywhere, or the same price before tax.
 */
final class ShownPricesTest extends TestCase
{
    private TestShop $shop;
    private CartOwner $owner;
    private int $vat;

    protected function setUp(): void
    {
        // A shop in Bulgaria (20 %) selling into Germany (19 %), prices entered with tax.
        $this->shop = new TestShop();
        $this->vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($this->vat, new SaveTaxRate('BG', null, '20', 'VAT'));
        $this->shop->taxGroups->addRate($this->vat, new SaveTaxRate('DE', null, '19', 'MwSt'));
        $this->settings(true);
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('12', weight: '1'));
        $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Everywhere', '6')]));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
    }

    public function testTheSamePriceBeforeTaxPutsTheCustomersRateOnTopOfIt(): void
    {
        $this->settings(true, samePrice: false);

        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails('DE'));

        // 12 holds 2 of the shop's 20 %: 10 before tax, 11.90 with Germany's 19 %; shipping 5 and 5.95.
        self::assertSame(['11.9', '5.95', '17.85'], [$summary->subtotal, $summary->shipping, $summary->total]);
        self::assertSame([['MwSt', '1.9', '0.95']], array_map(static fn($tax): array => [$tax->name, $tax->items, $tax->shipping], $summary->taxes));
        self::assertSame('18', $this->shop->checkout->summary($this->owner, new CheckoutDetails('BG'))->total, 'at the shop\'s own rate, as entered');
        self::assertSame('15', $this->shop->checkout->summary($this->owner, new CheckoutDetails('US'))->total, 'an export, without the shop\'s tax');
    }

    public function testAnExemptCustomerPaysThePriceWithoutTheTaxInIt(): void
    {
        $buyer = $this->customerIn('DE');
        $this->shop->taxExemptions->add($buyer, new SaveTaxExemption('resale', 'DE-1'));
        $owner = new CartOwner(null, $buyer);
        $this->shop->carts->add($owner, new AddToCart(10));

        // The same price everywhere: what a German customer pays, less Germany's tax in it.
        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('DE'));
        self::assertSame(['10.08', '5.04', '15.12'], [$summary->subtotal, $summary->shipping, $summary->total]);

        $this->settings(true, samePrice: false);
        self::assertSame('15', $this->shop->checkout->summary($owner, new CheckoutDetails('DE'))->total, 'less the shop\'s own tax');
    }

    public function testTheCatalogShowsPricesWithOrWithoutTaxAtTheVisitorsShop(): void
    {
        self::assertSame(['12', true], $this->shown(null), 'as entered');

        $this->settings(true, shop: TaxSettings::WITHOUT_TAX);
        self::assertSame(['10', false], $this->shown(null));

        $this->settings(false, shop: TaxSettings::WITH_TAX);
        self::assertSame(['14.4', true], $this->shown(null), 'entered before tax, shown with the shop\'s 20 %');
    }

    public function testASignedInCustomerSeesTheirOwnRate(): void
    {
        $buyer = $this->customerIn('DE');

        $this->settings(true, shop: TaxSettings::WITHOUT_TAX);
        self::assertSame(['10.084', false], $this->shown($buyer), '12 holds 19 %: 10.084 before it');

        $this->settings(true, shop: TaxSettings::WITHOUT_TAX, samePrice: false);
        self::assertSame(['10', false], $this->shown($buyer));
        $this->settings(true, shop: TaxSettings::WITH_TAX, samePrice: false);
        self::assertSame(['11.9', true], $this->shown($buyer));
    }

    public function testAPriceTakesEachPartsRates(): void
    {
        $this->settings(false, shop: TaxSettings::WITH_TAX);
        $offer = new ProductOffer(1, 'Box', 'bundle', null, '30', null, null, true, null, false, [], [], [], taxParts: [
            ['taxable' => true, 'group' => $this->vat, 'weight' => '20'],
            ['taxable' => false, 'group' => null, 'weight' => '10'],
        ]);

        $shown = $this->shop->shownPrices->offer($offer, null);

        self::assertSame(['34', '34', '30'], [$shown->price, $shown->priceWithTax, $shown->priceWithoutTax], '20 of it at 20 %, 10 untaxed');
    }

    public function testTheCartAndCheckoutShowTheirAmountsAsChosenWithTheSameTotal(): void
    {
        $this->settings(true, cart: TaxSettings::WITHOUT_TAX);

        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails('BG'));

        self::assertFalse($summary->pricesIncludeTax);
        self::assertSame(['10', '10', '10', '5', '18'], [$summary->lines[0]->unitPrice, $summary->lines[0]->total, $summary->subtotal, $summary->shipping, $summary->total]);
        self::assertSame([['VAT', '3']], array_map(static fn($tax): array => [$tax->name, $tax->total], $summary->taxes), 'the tax adds up to the total');

        $html = $this->shop->templates->render('storefront/_checkout_summary.html.twig', ['summary' => $summary, 'address_known' => true]);
        self::assertStringNotContainsString('Including VAT', $html);
        self::assertStringContainsString('VAT (20%)', $html);
    }

    public function testThePriceIsFollowedByTheShopsText(): void
    {
        $render = fn(): string => $this->shop->templates->render('storefront/_list_price.html.twig', ['offer' => $this->shop->shownPrices->offer($this->shop->catalog->offer(10), null)]);
        self::assertStringContainsString('incl. tax', $render(), 'prices with tax say so');

        $this->settings(true, suffix: '({price_excluding_tax} excl. VAT) & more');
        self::assertMatchesRegularExpression('/12\.00 EUR.*\(10\.00 EUR excl\. VAT\) &amp; more/', $render());

        $this->settings(false, shop: TaxSettings::WITHOUT_TAX);
        self::assertStringContainsString('excl. tax', $render());
        $this->settings(false);
        self::assertStringNotContainsString('tax', $render(), 'as entered, before tax: nothing said, as before');
    }

    public function testTheTextAfterPricesIsPlainText(): void
    {
        $this->expectException(\OrillaCart\Shared\Domain\RuleViolation::class);
        $this->settings(true, suffix: '<script>');
    }

    private function settings(bool $includesTax, string $shop = TaxSettings::AS_ENTERED, string $cart = TaxSettings::AS_ENTERED, string $suffix = '', bool $samePrice = true): void
    {
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $this->vat, $includesTax, shownInShop: $shop, shownInCart: $cart, priceSuffix: $suffix, samePriceEverywhere: $samePrice));
    }

    /** @return array{string, ?bool} */
    private function shown(?int $customerId): array
    {
        $offer = $this->shop->shownPrices->offer($this->shop->catalog->offer(10), $customerId);

        return [$offer->price, $offer->shownWithTax];
    }

    private function customerIn(string $country): int
    {
        $id = $this->shop->accounts->add(strtolower($country) . '@example.com');
        $this->shop->accounts->saveAddresses($id, new AddressBook()->remembering(new AddressData('Ada', 'Lovelace', '1 Main Street', 'Berlin', '10117', $country, '+49 30 000000'), null));

        return $id;
    }
}
