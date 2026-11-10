<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Ordering;

use Nyholm\Psr7\ServerRequest;
use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Customers\Application\Dto\CreateCustomer;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\ReceiptHandles;
use OrillaCart\Ordering\Http\PresentedReceipt;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Http\FakeAuthorization;
use PHPUnit\Framework\TestCase;

final class ReceiptHandlesTest extends TestCase
{
    private TestShop $shop;
    private ReceiptHandles $handles;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'Lamp');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->handles = new ReceiptHandles($this->shop->receipts, $this->shop->orders);
    }

    public function testATokenIsTakenAsItIs(): void
    {
        $token = $this->shop->receipts->issue(12);

        self::assertSame($token, $this->handles->token($token, null, null));
    }

    public function testAHandleStandsForTheTokenTheBrowserKeepsForThatSameOrderOnly(): void
    {
        $order = $this->place();
        $token = $this->shop->receipts->issue($order->number);
        $handle = ReceiptHandles::of($order->number);
        self::assertMatchesRegularExpression('/^(' . ReceiptHandles::PATTERN . ')$/', $handle);
        self::assertStringNotContainsString($token, $handle);

        self::assertSame($token, $this->handles->token($handle, $token, null));
        self::assertNull($this->handles->token($handle, null, null), 'nothing kept');
        self::assertNull($this->handles->token($handle, $this->shop->receipts->issue($order->number + 1), null), 'a receipt opened since in another tab');
        self::assertNull($this->handles->token($handle, $order->number . '-1-' . str_repeat('0', 32), null), 'a token the shop never made');
    }

    public function testTheLoggedInOwnerNeedsNoKeptToken(): void
    {
        $customer = $this->shop->customers->create(new CreateCustomer('ada@example.com', 'Ada', 'Lovelace'));
        $order = $this->place($customer->id);

        $token = $this->handles->token(ReceiptHandles::of($order->number), null, $customer->id);
        self::assertNotNull($token);
        self::assertSame($order->number, $this->shop->orders->byReceipt($token)->number);
        self::assertNull($this->handles->token(ReceiptHandles::of($order->number), null, $customer->id + 1), 'someone else\'s order');
    }

    public function testAnActionGivenAHandleWithoutATokenAnswersThatThePageWasOpenTooLong(): void
    {
        $order = $this->place();
        $presented = new PresentedReceipt($this->handles, new FakeAuthorization());
        $token = $this->shop->receipts->issue($order->number);
        $request = new ServerRequest('POST', '/wp-json/orillacart/v1/receipts/kept-1/reorder');

        self::assertSame($token, $presented->of($request->withCookieParams([ReceiptHandles::COOKIE => $token]), ReceiptHandles::of($order->number)));
        $this->expectExceptionObject(new HttpException(410, 'orillacart_receipt_not_kept', 'This page was open for too long. Open the link in your email again.'));
        $presented->of($request, ReceiptHandles::of($order->number));
    }

    private function place(?int $customerId = null): PlacedOrderView
    {
        $result = $this->shop->carts->add(new CartOwner(null, $customerId), new AddToCart(20));

        return $this->shop->checkout->placeOrder(new CartOwner($result->newToken, $customerId), new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }
}
