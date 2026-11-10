<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Payment;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Extension\TestPayGateway;
use PHPUnit\Framework\TestCase;

/** What an add-on's gateway says was paid is taken only when it says how much, and in what. */
final class AddOnPaymentTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop(gateways: [new TestPayGateway()]);
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update(TestPayGateway::ID, new SavePaymentMethod('Test Pay', true, settings: ['api_key' => 'key']));
    }

    public function testAPaymentInTheOrdersCurrencyPaysTheOrder(): void
    {
        $placed = $this->place();

        $this->back(['amount' => '8.00', 'currency' => 'EUR']);

        self::assertSame('processing', $this->shop->orders->order($placed->number)->status);
    }

    public function testAPaymentThatDoesNotSayItsCurrencyWaitsForTheShop(): void
    {
        $placed = $this->place();

        $this->back(['amount' => '8.00', 'currency' => 'none']);

        $order = $this->shop->orders->order($placed->number);
        self::assertNull($order->paidAt, '8 of what the provider did not say is not the order\'s 8 EUR');
        self::assertSame('on_hold', $order->status);
    }

    public function testAPaymentInAnotherCurrencyWaitsForTheShop(): void
    {
        $placed = $this->place();

        $this->back(['amount' => '8.00', 'currency' => 'HUF']);

        self::assertNull($this->shop->orders->order($placed->number)->paidAt);
    }

    /** @param array<string, string> $query */
    private function back(array $query): void
    {
        $payment = $this->shop->paymentRecords->findByReference(TestPayGateway::ID, 'REF-1');
        self::assertNotNull($payment);
        $this->shop->outcomes->returned(TestPayGateway::ID, ['ref' => 'REF-1'] + $query + $this->shop->paymentUrls->query(TestPayGateway::ID, $payment->id()), false);
    }

    private function place(): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: TestPayGateway::ID));
    }
}
