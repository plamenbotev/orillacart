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
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Prices entered including tax: every customer pays them as entered, and the tax in them is their own
 * country's; a customer who owes no tax pays them less the shop's.
 */
final class PricesIncludingTaxTest extends TestCase
{
    private TestShop $shop;
    private CartOwner $owner;

    protected function setUp(): void
    {
        // A shop in Bulgaria, charging each EU country its own rate.
        $this->shop = new TestShop();
        $vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($vat, new SaveTaxRate('BG', null, '20', 'VAT'));
        $this->shop->taxGroups->addRate($vat, new SaveTaxRate('DE', null, '19', 'MwSt'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $vat, true));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('12', weight: '1'));
        $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Everywhere', '6')]));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
    }

    public function testACustomerAtTheShopsRatePaysThePriceWithItsTaxInIt(): void
    {
        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails('BG'));

        self::assertTrue($summary->pricesIncludeTax);
        self::assertSame(['12', '6', '18'], [$summary->subtotal, $summary->shipping, $summary->total]);
        self::assertSame([['VAT', '2', '1', '3']], array_map(static fn($tax): array => [$tax->name, $tax->items, $tax->shipping, $tax->total], $summary->taxes));
        self::assertSame('6', $summary->shippingOptions[0]->total, 'shipping as entered, tax in it');
    }

    public function testACustomerElsewhereInTheEuPaysTheSamePriceWithTheirRateInIt(): void
    {
        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails('DE'));

        self::assertSame('18', $summary->total);
        // 12 holds 1.9160 of 19 %, 6 holds 0.9580.
        self::assertSame([['MwSt', '1.92', '0.96']], array_map(static fn($tax): array => [$tax->name, $tax->items, $tax->shipping], $summary->taxes));
    }

    public function testACustomerWhoOwesNoTaxPaysThePriceLessTheShopsTax(): void
    {
        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails('US'));

        self::assertSame(['10', '5', '15', []], [$summary->subtotal, $summary->shipping, $summary->total, $summary->taxes]);
    }

    public function testTheOrderAndItsReceiptSayTheTaxIsIncluded(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: $this->shop->checkout->summary($this->owner, new CheckoutDetails('BG'))->shippingOption, payment: 'bank_transfer'));

        $order = $this->shop->orders->byKey($placed->key);
        self::assertSame([true, '18', '2'], [$order->pricesIncludeTax, $order->total, $order->itemsTax]);
        $html = $this->shop->templates->render('storefront/receipt.html.twig', ['order' => $order, 'heading' => true, 'links' => []]);
        self::assertStringContainsString('Including VAT (20%)', $html);
    }
}
