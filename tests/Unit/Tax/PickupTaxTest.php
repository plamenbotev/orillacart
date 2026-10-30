<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CheckoutView;
use OrillaCart\Checkout\Application\Dto\CreateOrder;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SavePickupLocation;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * An order collected at one of the shop's places is handed over there: taxed at the shop's address, a sale at
 * home for a business of another EU country, and no export for a shopper from outside the EU.
 */
final class PickupTaxTest extends TestCase
{
    private TestShop $shop;
    private string $pickup;
    private string $courier;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        foreach (['BG' => ['20', 'ДДС'], 'FR' => ['20', 'TVA'], 'CH' => ['8.1', 'MWST']] as $country => [$percent, $name]) {
            $this->shop->taxGroups->addRate($vat, new SaveTaxRate($country, null, $percent, $name));
        }
        // The One-Stop-Shop: the customer's EU country, no tax outside the EU.
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::EuDestination, $vat, false, true));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '1', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->courier = 'rate:' . $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Europe', '5', taxable: false, regions: ['FR', 'CH'])]))->rates[0]->id;
        $pickup = $this->shop->carriers->create(new SaveCarrier('Pickup', pickupLocations: [new SavePickupLocation('sofia', 'Sofia shop', '1 Vitosha Blvd, 1000 Sofia')]))->id;
        $this->pickup = "pickup:{$pickup}:sofia";
        $this->shop->vies->known = ['FR40303265045' => 'Exemple SARL'];
    }

    public function testAFrenchConsumerCollectingInSofiaPaysBulgarianVat(): void
    {
        $owner = $this->cart();

        self::assertSame([['TVA', '6']], self::taxes($this->summary($owner, 'FR', $this->courier)), 'delivered to France: French VAT');
        self::assertSame([['ДДС', '6']], self::taxes($this->summary($owner, 'FR', $this->pickup)), 'collected in Sofia: Bulgarian VAT');
        self::assertSame([['TVA', '6']], self::taxes($this->summary($owner, 'FR', $this->courier)), 'switching back prices it again');

        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('marie@example.fr', self::french(), shippingOption: $this->pickup, payment: 'bank_transfer'));
        self::assertSame([['ДДС', 'BG']], array_map(static fn($tax): array => [$tax->name, $tax->country], $this->shop->orders->order($placed->number)->lines[0]->taxes));
    }

    public function testAFrenchBusinessCollectingInSofiaGetsNoReverseChargeForTheGoods(): void
    {
        $owner = $this->cart();

        $delivered = $this->summary($owner, 'FR', $this->courier, 'FR40303265045');
        self::assertSame([true, []], [$delivered->reverseCharge, self::taxes($delivered)]);

        $collected = $this->summary($owner, 'FR', $this->pickup, 'FR40303265045');
        self::assertSame([false, [['ДДС', '6']]], [$collected->reverseCharge, self::taxes($collected)]);
        self::assertSame('The goods are delivered in the shop\'s own country, so VAT is charged, even with a VAT number of another EU country.', $collected->vatMessage);

        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('achats@exemple.fr', self::french(), shippingOption: $this->pickup, payment: 'bank_transfer', vatNumber: 'FR40303265045'));
        $order = $this->shop->orders->order($placed->number);
        self::assertSame([false, 'FR40303265045', '6'], [$order->reverseCharge, $order->vatNumber, $order->itemsTax]);
    }

    public function testASwissShopperCollectingInSofiaPaysBulgarianVatNotAnExport(): void
    {
        $owner = $this->cart();

        self::assertSame([], self::taxes($this->summary($owner, 'CH', $this->courier)), 'sent out of the EU: no VAT');
        self::assertSame([['ДДС', '6']], self::taxes($this->summary($owner, 'CH', $this->pickup)), 'handed over in Sofia');
    }

    public function testAnOrderTheShopTakesForPickupIsTaxedAtTheShop(): void
    {
        $order = $this->shop->manualOrders->create(new CreateOrder('marie@example.fr', self::french(), [new EditedItem(10, 1)], shippingOption: $this->pickup, vatNumber: 'FR40303265045'), 7);

        self::assertSame([false, 'FR40303265045', '6'], [$order->reverseCharge, $order->vatNumber, $order->itemsTax]);
    }

    private function summary(CartOwner $owner, string $country, string $option, ?string $vatNumber = null): CheckoutView
    {
        return $this->shop->checkout->summary($owner, new CheckoutDetails($country, null, $country, null, $option, vatNumber: $vatNumber));
    }

    /**
     * @return list<array{string, string}>
     */
    private static function taxes(CheckoutView $summary): array
    {
        return array_map(static fn($tax): array => [$tax->name, $tax->total], $summary->taxes);
    }

    private static function french(): AddressData
    {
        return new AddressData('Marie', 'Dupont', '1 Rue de Rivoli', 'Paris', '75001', 'FR', '+33 1 00 00 00 00');
    }

    private function cart(): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
    }
}
