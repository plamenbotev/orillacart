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
use OrillaCart\Customers\Application\Dto\ConfirmAccount;
use OrillaCart\Customers\Application\Dto\CustomerQuery;
use OrillaCart\Customers\Application\Dto\RegisterAccount;
use OrillaCart\Customers\Application\Dto\SaveAddress;
use OrillaCart\Customers\Application\Dto\SaveCustomerGroup;
use OrillaCart\Customers\Application\Dto\SetCustomerGroups;
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

    public function testAVisitorAsksForAnAccountWithTheOrderAndGetsItWithTheLink(): void
    {
        $order = $this->place(new CartOwner(null), createAccount: true);

        self::assertNull($this->shop->accounts->loggedIn, 'nothing until the link is followed');
        self::assertFalse($this->shop->accounts->isRegistered('ada@example.com'));
        self::assertSame([['confirm', 'ada@example.com']], array_map(static fn(array $mail): array => [$mail['kind'], $mail['to']], $this->shop->accountMail->sent));

        $customer = $this->shop->customers->confirmAccount(new ConfirmAccount($this->shop->accountMail->tokenFor('ada@example.com'), 'correct horse'));

        self::assertSame($customer->id, $this->shop->accounts->loggedIn);
        self::assertSame('correct horse', $this->shop->accounts->passwords[$customer->id]);
        self::assertSame(['ada@example.com', 'Ada', 'Lovelace', 1], [$customer->email, $customer->firstName, $customer->lastName, $customer->orders]);
        self::assertEquals($this->address, $customer->billing, 'the order\'s addresses are saved for next time');
        self::assertSame($order->number, $this->shop->orders->customerOrder($customer->id, $order->number)->number);
        self::assertSame([], $this->shop->pendingAccounts->accounts, 'the link works once');
        self::assertSame([['confirm', 'ada@example.com'], ['welcome', 'ada@example.com']], array_map(static fn(array $mail): array => [$mail['kind'], $mail['to']], $this->shop->accountMail->sent));
    }

    public function testSigningUpSaysNothingOfWhetherTheEmailHasAnAccount(): void
    {
        $this->shop->accounts->add('ada@example.com');

        $this->shop->customers->requestAccount(new RegisterAccount('ada@example.com', 'Ada', 'Lovelace'));
        $this->shop->customers->requestAccount(new RegisterAccount('grace@example.com', 'Grace', 'Hopper'));
        $order = $this->place(new CartOwner(null), 'ada@example.com', true);

        self::assertSame([['exists', 'ada@example.com'], ['confirm', 'grace@example.com'], ['exists', 'ada@example.com']], array_map(static fn(array $mail): array => [$mail['kind'], $mail['to']], $this->shop->accountMail->sent));
        self::assertNull($this->shop->orders->order($order->number)->customerId, 'a guest order: whoever ordered may not own the account');
    }

    public function testALinkWorksOnceWithAGoodPasswordAndOnlyTheLatest(): void
    {
        $this->shop->customers->requestAccount(new RegisterAccount('ada@example.com', 'Ada', 'Lovelace'));
        $first = $this->shop->accountMail->tokenFor('ada@example.com');
        $this->shop->customers->requestAccount(new RegisterAccount('ada@example.com', 'Ada', 'Lovelace'));
        $latest = $this->shop->accountMail->tokenFor('ada@example.com');

        foreach ([[$first, 'correct horse', 'orillacart_invalid_link'], [$latest, 'short', 'orillacart_weak_password'], [str_repeat('a', 64), 'correct horse', 'orillacart_invalid_link']] as [$token, $password, $code]) {
            try {
                $this->shop->customers->confirmAccount(new ConfirmAccount($token, $password));
                self::fail($code);
            } catch (RuleViolation $e) {
                self::assertSame($code, $e->errorCode);
            }
        }

        $this->shop->clock->modify('+49 hours');
        try {
            $this->shop->customers->confirmAccount(new ConfirmAccount($latest, 'correct horse'));
            self::fail('expired');
        } catch (RuleViolation $e) {
            self::assertSame('orillacart_invalid_link', $e->errorCode);
        }
        $this->shop->customers->forgetUnconfirmed();
        self::assertSame([], $this->shop->pendingAccounts->accounts);
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
        $this->place(new CartOwner(null), 'guest@example.com', true);
        self::assertSame([], $this->shop->accountMail->sent, 'no accounts when they are off');
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

        $saved = $this->shop->customers->saveAddress($ada, null, new SaveAddress($this->address));
        self::assertEquals([$this->address, $this->address], [$saved->billing, $saved->shippingAddress], 'the first is the one to start from');
    }

    public function testACustomerKeepsAnAddressBookAndChoosesWhichToStartFrom(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com');
        $office = new AddressData('Ada', 'Lovelace', '5 Shipka St', 'Sofia', '1504', 'BG', '+359 2 111 1111');
        $home = $this->shop->customers->saveAddress($ada, null, new SaveAddress($this->address))->addresses[0]->id;

        $customer = $this->shop->customers->saveAddress($ada, null, new SaveAddress($office, shipping: true));
        self::assertEquals([$this->address, $office], [$customer->billing, $customer->shippingAddress]);
        self::assertSame([[$home, true, false], [$customer->addresses[1]->id, false, true]], array_map(static fn($saved): array => [$saved->id, $saved->billing, $saved->shipping], $customer->addresses));
        self::assertEquals($office, $this->shop->customers->savedAddress($ada), 'estimates go where things are sent');

        $moved = new AddressData('Ada', 'Lovelace', '9 Rakovski St', 'Sofia', '1000', 'BG', '+359 2 000 0000');
        $customer = $this->shop->customers->saveAddress($ada, $home, new SaveAddress($moved));
        self::assertEquals([$moved, 2], [$customer->billing, \count($customer->addresses)], 'changed in place');

        $customer = $this->shop->customers->removeAddress($ada, $customer->addresses[1]->id);
        self::assertEquals([$moved, $moved], [$customer->billing, $customer->shippingAddress], 'shipping falls back to billing');

        $this->expectException(RuleViolation::class);
        $this->shop->customers->removeAddress($ada, 'ffffffff');
    }

    public function testOrderingAddsTheAddressesOnceEachAsTheOnesToStartFrom(): void
    {
        $id = $this->shop->accounts->add('ada@example.com');
        $this->place(new CartOwner(null, $id));
        $this->place(new CartOwner(null, $id));

        $customer = $this->shop->customers->customer($id);
        self::assertCount(1, $customer->addresses);
        self::assertSame([true, true], [$customer->addresses[0]->billing, $customer->addresses[0]->shipping]);
    }

    public function testADeletedAccountsOrdersBecomeGuestOrdersAndItsCartAndGroupsGo(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com');
        $grace = $this->shop->accounts->add('grace@example.com');
        $group = $this->shop->customerGroups->create(new SaveCustomerGroup('Wholesale'));
        $this->shop->customerGroups->setGroupsOf($ada, new SetCustomerGroups([$group->id]));
        $this->shop->customerGroups->setGroupsOf($grace, new SetCustomerGroups([$group->id]));
        $first = $this->place(new CartOwner(null, $ada));
        $second = $this->place(new CartOwner(null, $ada));
        $this->cart(new CartOwner(null, $ada));
        $this->place(new CartOwner(null, $grace), 'grace@example.com');

        $this->shop->customers->forget($ada);

        foreach ([$first, $second] as $placed) {
            $order = $this->shop->orderRecords->orders[$placed->number];
            self::assertNull($order->customerId());
            self::assertSame('ada@example.com', $order->email(), 'kept under the email given');
            $notes = $this->shop->orders->order($placed->number)->notes;
            self::assertStringContainsString('account of customer #' . $ada . ' was deleted', $notes[\count($notes) - 1]->text);
        }
        self::assertSame(1, $this->shop->orders->orders(new OrderQuery(customerId: $grace))->total, 'other customers keep theirs');
        self::assertNull($this->shop->cartRecords->findByCustomer($ada));
        self::assertSame([], $this->shop->customerGroups->groupIdsOf($ada));
        self::assertSame([$group->id], $this->shop->customerGroups->groupIdsOf($grace));
    }

    private function cart(CartOwner $owner): CartOwner
    {
        $result = $this->shop->carts->add($owner, new AddToCart(20));

        return new CartOwner($result->newToken ?? $owner->token, $owner->customerId);
    }

    private function place(CartOwner $owner, string $email = 'ada@example.com', bool $createAccount = false, bool $rememberAddresses = true): PlacedOrderView
    {
        return $this->shop->checkout->placeOrder($this->cart($owner), new PlaceOrder($email, $this->address, payment: 'bank_transfer', createAccount: $createAccount, rememberAddresses: $rememberAddresses));
    }
}
