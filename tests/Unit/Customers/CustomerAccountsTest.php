<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Customers;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Customers\Application\Dto\CustomerQuery;
use OrillaCart\Customers\Application\Dto\SaveAddresses;
use OrillaCart\Customers\Application\Dto\UpdateCustomerSettings;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class CustomerAccountsTest extends TestCase
{
    private TestShop $shop;
    private AddressData $address;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->address = new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000');
    }

    public function testAVisitorMakesAnAccountWithTheOrderAndGetsIt(): void
    {
        $order = $this->place(new CartOwner(null), createAccount: true, password: 'correct horse');

        $id = $this->shop->accounts->loggedIn;
        self::assertNotNull($id, 'logged in right away');
        self::assertSame('correct horse', $this->shop->accounts->passwords[$id]);
        $customer = $this->shop->customers->customer($id);
        self::assertSame(['ada@example.com', 'Ada', 'Lovelace', 1], [$customer->email, $customer->firstName, $customer->lastName, $customer->orders]);
        self::assertEquals($this->address, $customer->billing, 'the addresses are saved for next time');
        self::assertSame($order->number, $this->shop->orders->customerOrder($id, $order->number)->number);
    }

    public function testAnAccountIsCheckedBeforeAnythingIsOrdered(): void
    {
        $this->shop->accounts->add('ada@example.com');
        foreach ([['ada@example.com', 'correct horse', 'orillacart_account_exists'], ['new@example.com', 'short', 'orillacart_weak_password']] as [$email, $password, $code]) {
            try {
                $this->place(new CartOwner(null), $email, true, $password);
                self::fail("{$email} with {$password}");
            } catch (RuleViolation $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
        self::assertSame([], $this->shop->orderRecords->orders);
    }

    public function testAShopCanRequireAccountsOrTurnThemOff(): void
    {
        $this->shop->customers->updateSettings(new UpdateCustomerSettings('required'));
        self::assertSame('required', $this->shop->checkout->summary($this->cart(new CartOwner(null)), new CheckoutDetails('BG'))->accounts);
        try {
            $this->place(new CartOwner(null));
            self::fail('a guest order');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_account_required', $e->errorCode);
        }
        $customer = $this->shop->accounts->add('grace@example.com', 'Grace');
        self::assertSame('on_hold', $this->place(new CartOwner(null, $customer), 'grace@example.com')->status);

        $this->shop->customers->updateSettings(new UpdateCustomerSettings('disabled'));
        $this->place(new CartOwner(null), 'guest@example.com', true, 'correct horse');
        self::assertFalse($this->shop->accounts->isRegistered('guest@example.com'), 'no accounts when they are off');
    }

    public function testALoggedInCustomersAddressesAreRememberedUnlessTheySayNo(): void
    {
        $id = $this->shop->accounts->add('ada@example.com');

        $this->place(new CartOwner(null, $id), rememberAddresses: false);
        self::assertNull($this->shop->customers->customer($id)->billing);

        $this->place(new CartOwner(null, $id));
        self::assertEquals($this->address, $this->shop->customers->customer($id)->billing);
        self::assertSame(2, $this->shop->orders->orders(new OrderQuery(customerId: $id))->total);
    }

    public function testCustomersSeeOnlyTheirOwnOrders(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com');
        $grace = $this->shop->accounts->add('grace@example.com');
        $order = $this->place(new CartOwner(null, $ada));

        $this->expectException(OrderNotFound::class);
        $this->shop->orders->customerOrder($grace, $order->number);
    }

    public function testTheShopListsCustomersAndEditsTheirAddresses(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com');
        $this->shop->accounts->add('grace@example.com', 'Grace');

        $page = $this->shop->customers->customers(new CustomerQuery('ada'));
        self::assertSame([[$ada], 1], [array_column($page->customers, 'id'), $page->total]);

        $saved = $this->shop->customers->saveAddresses($ada, new SaveAddresses($this->address, $this->address));
        self::assertEquals($this->address, $saved->shippingAddress);
    }

    private function cart(CartOwner $owner): CartOwner
    {
        $result = $this->shop->carts->add($owner, new AddToCart(20));

        return new CartOwner($result->newToken ?? $owner->token, $owner->customerId);
    }

    private function place(CartOwner $owner, string $email = 'ada@example.com', bool $createAccount = false, ?string $password = null, bool $rememberAddresses = true): PlacedOrderView
    {
        return $this->shop->checkout->placeOrder($this->cart($owner), new PlaceOrder($email, $this->address, payment: 'bank_transfer', createAccount: $createAccount, password: $password, rememberAddresses: $rememberAddresses));
    }
}
