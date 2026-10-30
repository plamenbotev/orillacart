<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\AdjustOrderPrice;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\EditOrderItems;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class OrderEditingTest extends TestCase
{
    private TestShop $shop;
    private int $rate;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($vat, new SaveTaxRate('BG', null, '20', 'VAT'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $vat));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '2'));
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('10', 'virtual'));
        $room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($room, 5)]));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '5', regions: ['BG'])]))->rates[0]->id;
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testTheShopChangesQuantitiesAndPricesAndTheStockFollows(): void
    {
        $order = $this->place([10, 2]);
        self::assertSame(3, $this->available());

        $edited = $this->shop->editing->edit($order->number, new EditOrderItems([new EditedItem(10, 4, unitPrice: '25'), new EditedItem(20, 1)], 'rate:' . $this->rate), 7);

        self::assertSame([['Lamp', 4, '25', '100'], ['E-book', 1, '10', '10']], array_map(static fn($line): array => [$line->name, $line->quantity, $line->unitPrice, $line->total], $edited->lines));
        // 110 + 22 VAT + 5 shipping + 1 VAT on shipping
        self::assertSame(['110', '22', '5', '1', '138'], [$edited->subtotal, $edited->itemsTax, $edited->shippingTotal, $edited->shippingTax, $edited->total]);
        self::assertSame(1, $this->available(), 'two lamps went back and four were taken');
        self::assertStringContainsString('the total went from 78 to 138 EUR', $edited->notes[2]->text);
    }

    public function testAnOrderThatStopsShippingLosesItsShippingAndOneThatStartsShipsToTheBillingAddress(): void
    {
        $order = $this->place([10, 1]);
        $digital = $this->shop->editing->edit($order->number, new EditOrderItems([new EditedItem(20, 1)]), 7);
        self::assertSame([null, null, '12'], [$digital->shipping, $digital->shippingAddress, $digital->total]);

        $physical = $this->shop->editing->edit($order->number, new EditOrderItems([new EditedItem(10, 1)], 'rate:' . $this->rate), 7);
        self::assertSame(['Courier – Bulgaria', 'Sofia'], [$physical->shipping, $physical->shippingAddress?->city]);
    }

    public function testShippingMustBeChosenWhenSomethingShips(): void
    {
        $order = $this->place([20, 1]);

        $this->expectException(CheckoutRuleViolation::class);
        $this->expectExceptionMessage('Choose how the order ships.');
        $this->shop->editing->edit($order->number, new EditOrderItems([new EditedItem(10, 1)]), 7);
    }

    public function testOnlyOrdersOnHoldChange(): void
    {
        $order = $this->place([20, 1]);
        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('processing'), 7);

        $this->expectException(OrderRuleViolation::class);
        $this->shop->editing->edit($order->number, new EditOrderItems([new EditedItem(20, 2)]), 7);
    }

    public function testAPriceTheOrderKeepsIsWhatTheCustomerPaysAndAnEditLeavesIt(): void
    {
        // Prices with tax, a customer outside the EU: they pay the e-book's 10 without the 20 % VAT in it.
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $this->shop->taxSettings->load()->defaultGroupId, pricesIncludeTax: true));
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
        $order = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Main St', 'Tokyo', '100-0001', 'JP', '+81 3 0000 0000'), payment: 'bank_transfer'));
        self::assertSame('8.33', $this->shop->orders->order($order->number)->lines[0]->unitPrice);

        $this->shop->editing->adjust($order->number, new AdjustOrderPrice('-1', 'Goodwill'), 7);
        $edited = $this->shop->editing->removeAdjustment($order->number, $this->shop->orders->order($order->number)->priceChanges[0]->change, 7);

        self::assertSame(['8.33', '8.33'], [$edited->lines[0]->unitPrice, $edited->total], 'the tax is not taken out again at each edit');
    }

    public function testAnEditKeepsTheOrdersAutomaticDiscountsAndTakesNoNewOnes(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'percent', '10', 'Spring sale', automatic: true));
        $order = $this->place([20, 1]);
        self::assertSame('10.8', $order->total, '10 less 10 %, with VAT');
        $this->shop->coupons->create(new SaveCoupon('', 'percent', '50', 'Black Friday', automatic: true));

        $edited = $this->shop->editing->edit($order->number, new EditOrderItems([new EditedItem(20, 2)]), 7);

        self::assertSame(['Spring sale'], array_map(static fn($discount): string => $discount->description, $edited->discounts), 'last week\'s order does not take this week\'s sale');
        self::assertSame('21.6', $edited->total);
        self::assertSame([], array_filter(array_map(static fn($note): string => $note->text, $edited->notes), static fn(string $text): bool => str_contains($text, 'no longer applies')), 'its own discount is not "a code that no longer applies"');
    }

    private function available(): ?int
    {
        return $this->shop->stock->stock(StockItemRef::product(10))->available;
    }

    /**
     * @param array{int, int} $line
     */
    private function place(array $line): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart($line[0], quantity: $line[1]))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: $line[0] === 10 ? 'rate:' . $this->rate : null, payment: 'bank_transfer'));
    }

    public function testAnEditedUnitPriceWithMoreDecimalsThanTheCurrencyIsTakenInCents(): void
    {
        $order = $this->place([20, 1]);

        $edited = $this->shop->editing->edit($order->number, new EditOrderItems([new EditedItem(20, 3, unitPrice: '1.2345')]), 7);

        self::assertSame(['1.23', '3.69', '4.43'], [$edited->lines[0]->unitPrice, $edited->lines[0]->total, $edited->total]);
    }
}
