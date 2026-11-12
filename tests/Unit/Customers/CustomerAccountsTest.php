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
use OrillaCart\Customers\Application\Dto\CreateCustomer;
use OrillaCart\Customers\Application\Dto\CustomerQuery;
use OrillaCart\Customers\Application\Dto\CustomerRecord;
use OrillaCart\Customers\Application\Dto\RegisterAccount;
use OrillaCart\Customers\Application\Dto\SaveAddress;
use OrillaCart\Customers\Application\Dto\SaveCustomerGroup;
use OrillaCart\Customers\Application\Dto\SetCustomerGroups;
use OrillaCart\Customers\Application\Dto\UpdateCustomerSettings;
use OrillaCart\Customers\Domain\InvitationNotFound;
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

    public function testOnANetworkTheStaffInviteRatherThanMakeAccounts(): void
    {
        $this->shop->accounts->elsewhere[501] = new CustomerRecord(501, 'boss@example.com', 'Grace', 'Hopper', '2025-01-01T00:00:00+00:00');
        self::assertTrue($this->shop->customers->invites(false));
        self::assertFalse($this->shop->customers->invites(true), 'the network\'s administrators see its users anyway');
        try {
            $this->shop->customers->create(new CreateCustomer('boss@example.com', 'Grace', 'Hopper'));
            self::fail('a user of another site was made one of this shop\'s');
        } catch (RuleViolation $e) {
            self::assertSame('orillacart_invite_instead', $e->errorCode);
        }
        self::assertFalse($this->shop->accounts->isRegistered('boss@example.com'));
        self::assertSame([], $this->shop->accountMail->sent, 'nothing went');
    }

    public function testOnANetworkTheShopInvitesEveryEmailAlikeAndMailsItOnceWhileTheLinkIsGood(): void
    {
        $this->shop->accounts->elsewhere[501] = new CustomerRecord(501, 'boss@example.com', 'Grace', 'Hopper', '2025-01-01T00:00:00+00:00');
        $answers = [];
        foreach (['boss@example.com', 'nobody@example.com', 'boss@example.com', 'nobody@example.com'] as $email) {
            $invitation = $this->shop->customers->invite(new CreateCustomer($email, 'Grace', 'Hopper'), 7);
            $answers[] = [$invitation->firstName, $invitation->lastName, $invitation->groupIds, $invitation->invitedBy, $invitation->expired, $invitation->sentAt, $invitation->expiresAt];
        }
        self::assertCount(1, array_unique(array_map(serialize(...), $answers)), 'the same answer whether another site has the email or nobody does');
        self::assertSame([['invite', 'boss@example.com'], ['invite', 'nobody@example.com']], array_map(static fn(array $mail): array => [$mail['kind'], $mail['to']], $this->shop->accountMail->sent), 'one link each while it is good');
        self::assertFalse($this->shop->accounts->isRegistered('boss@example.com'), 'they join when they follow the link');
        self::assertSame(['nobody@example.com', 'boss@example.com'], array_map(static fn($invitation): string => $invitation->email, $this->shop->customers->invitations()));

        $this->shop->clock->modify('+8 days');
        $this->shop->customers->invite(new CreateCustomer('nobody@example.com'), 7);
        self::assertCount(3, $this->shop->accountMail->sent, 'a new link once the last stopped working');

        $tester = $this->shop->customers->create(new CreateCustomer('tester@shop.test', 'Test', 'Buyer', test: true));
        self::assertTrue($tester->test, 'a test customer is made, of a domain kept for tests');
        try {
            $this->shop->customers->create(new CreateCustomer('tester@gmail.com', test: true));
            self::fail('a test customer of a real domain on a network');
        } catch (RuleViolation $e) {
            self::assertSame('orillacart_test_email', $e->errorCode);
        }
    }

    public function testAnInvitedCustomerJoinsTheGroupsTheShopChoseWhenTheyFollowTheLink(): void
    {
        $this->shop->accounts->network = true;
        $wholesale = $this->shop->customerGroups->create(new SaveCustomerGroup('Wholesale'));
        $gone = $this->shop->customerGroups->create(new SaveCustomerGroup('Gone'));
        $this->shop->customers->invite(new CreateCustomer('ada@example.com', 'Ada', 'Lovelace', groupIds: [$wholesale->id, $gone->id]), 7);
        $this->shop->customerGroups->delete($gone->id);

        $customer = $this->shop->customers->confirmAccount(new ConfirmAccount($this->shop->accountMail->tokenFor('ada@example.com'), 'correct horse'));

        self::assertSame([$wholesale->id], $this->shop->customerGroups->groupIdsOf($customer->id), 'the groups still there');
        self::assertSame([], $this->shop->customers->invitations(), 'followed, it is done');
    }

    public function testAVisitorWhoSignsUpThemselvesKeepsTheGroupsTheShopInvitedThemInto(): void
    {
        $this->shop->accounts->network = true;
        $wholesale = $this->shop->customerGroups->create(new SaveCustomerGroup('Wholesale'));
        $this->shop->customers->invite(new CreateCustomer('ada@example.com', 'Ada', 'Lovelace', groupIds: [$wholesale->id]), 7);

        $this->shop->customers->requestAccount(new RegisterAccount('ada@example.com', 'Ada', 'Lovelace'));
        $customer = $this->shop->customers->confirmAccount(new ConfirmAccount($this->shop->accountMail->tokenFor('ada@example.com'), 'correct horse'));

        self::assertSame([$wholesale->id], $this->shop->customerGroups->groupIdsOf($customer->id));
    }

    public function testInvitingAgainWhileTheLinkWorksTakesTheNewNamesAndSendsNoOtherLink(): void
    {
        $this->shop->accounts->network = true;
        $this->shop->customers->invite(new CreateCustomer('ada@example.com', 'Ada', 'Lovelase'), 7);
        $link = $this->shop->accountMail->tokenFor('ada@example.com');

        $again = $this->shop->customers->invite(new CreateCustomer('ada@example.com', '', 'Lovelace'), 7);

        self::assertSame(['Ada', 'Lovelace'], [$again->firstName, $again->lastName], 'the name given replaces, the one left empty stays');
        self::assertCount(1, $this->shop->pendingAccounts->accounts);
        self::assertSame($link, $this->shop->accountMail->tokenFor('ada@example.com'));
        $customer = $this->shop->customers->confirmAccount(new ConfirmAccount($link, 'correct horse'));
        self::assertSame('Lovelace', $customer->lastName);
    }

    public function testAnInvitationGoesAgainOnceADayAtMostAndCanBeWithdrawn(): void
    {
        $this->shop->accounts->network = true;
        $invitation = $this->shop->customers->invite(new CreateCustomer('ada@example.com', 'Ada'), 7);
        self::assertFalse($invitation->mayResend);
        try {
            $this->shop->customers->resendInvitation($invitation->id);
            self::fail('sent again within the hour');
        } catch (RuleViolation $e) {
            self::assertSame('orillacart_invitation_recent', $e->errorCode);
        }
        $first = $this->shop->accountMail->tokenFor('ada@example.com');

        $this->shop->clock->modify('+25 hours');
        $again = $this->shop->customers->resendInvitation($invitation->id);
        $second = $this->shop->accountMail->tokenFor('ada@example.com');
        self::assertNotSame($first, $second);
        self::assertFalse($again->mayResend, 'the next once another day went');
        try {
            $this->shop->customers->confirmAccount(new ConfirmAccount($first, 'correct horse'));
            self::fail('the old link still works');
        } catch (RuleViolation $e) {
            self::assertSame('orillacart_invalid_link', $e->errorCode);
        }

        $this->shop->customers->withdrawInvitation($invitation->id);
        self::assertSame([], $this->shop->customers->invitations());
        $this->expectException(InvitationNotFound::class);
        $this->shop->customers->resendInvitation($invitation->id);
    }

    public function testAnExpiredInvitationIsKeptAMonthForTheShopToSendAgain(): void
    {
        $this->shop->accounts->network = true;
        $this->shop->customers->invite(new CreateCustomer('ada@example.com'), 7);
        $this->shop->customers->requestAccount(new RegisterAccount('grace@example.com', 'Grace', 'Hopper'));

        $this->shop->clock->modify('+10 days');
        $this->shop->customers->forgetUnconfirmed();
        self::assertSame(['ada@example.com'], array_map(static fn($account): string => $account->email(), $this->shop->pendingAccounts->accounts), 'a visitor\'s sign-up goes; the invitation waits');
        self::assertTrue($this->shop->customers->invitations()[0]->mayResend);

        $this->shop->clock->modify('+30 days');
        $this->shop->customers->forgetUnconfirmed();
        self::assertSame([], $this->shop->pendingAccounts->accounts);
    }

    public function testTheNetworksAdministratorsMakeAccountsAndAddUsersOfOtherSites(): void
    {
        $this->shop->accounts->elsewhere[501] = new CustomerRecord(501, 'boss@example.com', 'Grace', 'Hopper', '2025-01-01T00:00:00+00:00');

        $joined = $this->shop->customers->create(new CreateCustomer('boss@example.com', 'Someone', 'Else'), seesNetwork: true);
        $made = $this->shop->customers->create(new CreateCustomer('nobody@example.com', 'Ada', 'Lovelace'), seesNetwork: true);

        self::assertSame([501, 'Grace'], [$joined->id, $joined->firstName], 'the user of another site joins as they are');
        self::assertArrayNotHasKey(501, $this->shop->accounts->passwords, 'their password is not changed');
        self::assertTrue($this->shop->accounts->isRegistered('nobody@example.com'));
        self::assertSame('Ada', $made->firstName);
        self::assertSame([], $this->shop->accountMail->sent, 'nobody is emailed');

        $this->shop->accounts->network = true;
        $this->shop->accounts->elsewhere[502] = new CustomerRecord(502, 'other@example.com', 'Alan', 'Turing', '2025-01-01T00:00:00+00:00');
        foreach (['tester@gmail.com', 'other@example.com'] as $email) {
            try {
                $this->shop->customers->create(new CreateCustomer($email, test: true), seesNetwork: true);
                self::fail("{$email} made a test customer");
            } catch (RuleViolation $e) {
                self::assertSame('orillacart_test_email', $e->errorCode, 'a real address, or a user of another site, is no test customer even for the network\'s administrators');
            }
        }
        self::assertTrue($this->shop->customers->create(new CreateCustomer('tester@shop.test', test: true), seesNetwork: true)->test);
    }

    public function testOnANetworkACustomerOfAnotherShopBecomesOneOfThisShopAfterConfirming(): void
    {
        $this->shop->accounts->elsewhere[500] = new \OrillaCart\Customers\Application\Dto\CustomerRecord(500, 'ada@example.com', 'Augusta', 'King', '2025-01-01T00:00:00+00:00');
        $order = $this->place(new CartOwner(null), createAccount: true);
        self::assertSame([['confirm', 'ada@example.com']], array_map(static fn(array $mail): array => [$mail['kind'], $mail['to']], $this->shop->accountMail->sent), 'a link, not word to log in to an account that is not this shop\'s');

        $customer = $this->shop->customers->confirmAccount(new ConfirmAccount($this->shop->accountMail->tokenFor('ada@example.com'), ''));

        self::assertSame([500, null], [$customer->id, $this->shop->accounts->loggedIn], 'joined, they log in as the network has them do: an emailed link alone is not a login');
        self::assertTrue($this->shop->accounts->isRegistered('ada@example.com'), 'a customer of this shop now');
        self::assertArrayNotHasKey(500, $this->shop->accounts->passwords, 'their password is not changed');
        self::assertSame(['Augusta', 'King'], [$customer->firstName, $customer->lastName], 'nor their details');
        self::assertSame($order->number, $this->shop->orders->customerOrder(500, $order->number)->number);
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

    public function testTheAccountTakesTheOrdersPlacedWithTheEmailAsAGuestBefore(): void
    {
        $earlier = $this->place(new CartOwner(null), 'Ada@Example.com');
        $other = $this->place(new CartOwner(null), 'grace@example.com');
        $order = $this->place(new CartOwner(null), createAccount: true);

        $customer = $this->shop->customers->confirmAccount(new ConfirmAccount($this->shop->accountMail->tokenFor('ada@example.com'), 'correct horse'));

        self::assertSame(2, $customer->orders, 'the link came to the email: its guest orders are its owner\'s');
        self::assertSame($earlier->number, $this->shop->orders->customerOrder($customer->id, $earlier->number)->number);
        self::assertSame($order->number, $this->shop->orders->customerOrder($customer->id, $order->number)->number);
        self::assertNull($this->shop->orders->order($other->number)->customerId);
    }

    public function testAnAccountMadeLaterKeepsTheAddressesOfTheLatestGuestOrder(): void
    {
        $this->place(new CartOwner(null));
        $moved = new AddressData('Ada', 'Lovelace', '5 Rakovski St', 'Plovdiv', '4000', 'BG', '+359 32 000 000');
        $this->shop->checkout->placeOrder($this->cart(new CartOwner(null)), new PlaceOrder('ada@example.com', $moved, payment: 'bank_transfer'));
        $this->shop->customers->requestAccount(new RegisterAccount('ada@example.com', 'Ada', 'Lovelace'));

        $customer = $this->shop->customers->confirmAccount(new ConfirmAccount($this->shop->accountMail->tokenFor('ada@example.com'), 'correct horse'));

        self::assertSame(2, $customer->orders);
        self::assertEquals($moved, $customer->billing, 'where they live now, not where they lived first');
    }

    public function testAShopMayKeepEarlierGuestOrdersOutOfANewAccount(): void
    {
        $this->shop->customers->updateSettings(new UpdateCustomerSettings('optional', guestOrders: false));
        $earlier = $this->place(new CartOwner(null));
        $order = $this->place(new CartOwner(null), createAccount: true);

        $customer = $this->shop->customers->confirmAccount(new ConfirmAccount($this->shop->accountMail->tokenFor('ada@example.com'), 'correct horse'));

        self::assertSame(1, $customer->orders, 'only the order placed while asking');
        self::assertSame($order->number, $this->shop->orders->customerOrder($customer->id, $order->number)->number);
        self::assertNull($this->shop->orders->order($earlier->number)->customerId);
        self::assertFalse($this->shop->customers->settings()->guestOrders);
        $this->shop->customers->updateSettings(new UpdateCustomerSettings('required'));
        self::assertFalse($this->shop->customers->settings()->guestOrders, 'left as it was');
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

    public function testAnExtensionReadsTheLatestOrdersAddressesWhereTheAccountKeepsNone(): void
    {
        $id = $this->shop->accounts->add('ada@example.com');
        $summaries = new \OrillaCart\Customers\Application\CustomerSummaries($this->shop->customers, new \OrillaCart\Shared\Validation\CommandChecks(\Symfony\Component\Validator\Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()), $this->shop->accounts);
        self::assertNull($summaries->find($id)?->billing, 'no account address, no order');

        $order = $this->place(new CartOwner(null, $id), rememberAddresses: false);
        $found = $summaries->find($id);
        self::assertNotNull($found);
        self::assertSame([$this->address->street, $order->number], [$found->billing?->street, $found->addressesFromOrder], 'the latest order\'s, and which it is');

        $this->place(new CartOwner(null, $id));
        self::assertNull($summaries->find($id)?->addressesFromOrder, 'the account keeps them now: its own');
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

    /** PLAN 361: the shop (and the assistant's create_customer) makes an account itself, without the sign-up emails. */
    public function testTheShopMakesACustomersAccountItself(): void
    {
        $made = $this->shop->customers->create(new CreateCustomer('grace@example.com', 'Grace', 'Hopper'));
        self::assertSame(['grace@example.com', 'Grace', 'Hopper'], [$made->email, $made->firstName, $made->lastName]);
        self::assertSame([], array_filter($this->shop->accountMail->sent, static fn(array $mail): bool => $mail['kind'] === 'welcome'), 'no welcome unless asked');

        $welcomed = $this->shop->customers->create(new CreateCustomer('Alan@Example.com', 'Alan', 'Turing', 'enigma1234'));
        self::assertSame('alan@example.com', $welcomed->email);
        $this->shop->customers->welcome($welcomed->id);
        self::assertSame(['alan@example.com'], array_column(array_values(array_filter($this->shop->accountMail->sent, static fn(array $mail): bool => $mail['kind'] === 'welcome')), 'to'));

        try {
            $this->shop->customers->create(new CreateCustomer('grace@example.com'));
            self::fail('an email with an account');
        } catch (RuleViolation $e) {
            self::assertSame('orillacart_account_exists', $e->errorCode);
        }
    }

    public function testAUserWhoIsNotTheShopsCustomerIsNotFoundAsOneButHasTheirOwnAccount(): void
    {
        $staff = $this->shop->accounts->add('boss@example.com', 'Grace', 'Hopper');
        $this->shop->accounts->staff[] = $staff;
        $summaries = new \OrillaCart\Customers\Application\CustomerSummaries($this->shop->customers, new \OrillaCart\Shared\Validation\CommandChecks(\Symfony\Component\Validator\Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()), $this->shop->accounts);

        self::assertNull($summaries->find($staff), 'a script asking by number learns nothing');
        foreach ([
            fn() => $this->shop->customers->customer($staff),
            fn() => $this->shop->customers->welcome($staff),
            fn() => $this->shop->customers->saveAddressOf($staff, null, new SaveAddress($this->address)),
            fn() => $this->shop->customers->removeAddressOf($staff, 'none'),
            fn() => $this->shop->customerGroups->setGroupsOf($staff, new SetCustomerGroups([])),
            fn() => $this->shop->customers->assignOrder(1, $staff, 7),
        ] as $reach) {
            try {
                $reach();
                self::fail('staff reached as a customer');
            } catch (\OrillaCart\Customers\Domain\CustomerNotFound) {
            }
        }
        self::assertSame([], $this->shop->accountMail->sent);

        // Logged in, they order at their own shop like anyone: their account is theirs to read and fill.
        self::assertSame('boss@example.com', $this->shop->customers->account($staff)->email);
        self::assertCount(1, $this->shop->customers->saveAddress($staff, null, new SaveAddress($this->address))->addresses);

        $customer = $this->shop->accounts->add('ada@example.com');
        self::assertSame('ada@example.com', $summaries->find($customer)?->email);
    }

    public function testACustomerIsAUserWhoseEveryRoleIsACustomersRole(): void
    {
        $roles = new \OrillaCart\Customers\Domain\CustomerSettings()->roles;
        self::assertSame(['orillacart_customer', 'subscriber'], $roles);
        self::assertTrue(\OrillaCart\Customers\Domain\CustomerSettings::areCustomers(['orillacart_customer'], $roles));
        self::assertTrue(\OrillaCart\Customers\Domain\CustomerSettings::areCustomers(['subscriber', 'orillacart_customer'], $roles));
        self::assertFalse(\OrillaCart\Customers\Domain\CustomerSettings::areCustomers(['editor'], $roles), 'another job on the site');
        self::assertFalse(\OrillaCart\Customers\Domain\CustomerSettings::areCustomers(['orillacart_customer', 'editor'], $roles), 'a customer given another job');
        self::assertFalse(\OrillaCart\Customers\Domain\CustomerSettings::areCustomers([], $roles), 'no role on this site');
    }

    public function testTheShopChoosesTheCustomersRolesAmongThoseTheSiteOffers(): void
    {
        $settings = $this->shop->customers->settings();
        self::assertSame(['orillacart_customer', 'subscriber'], $settings->roles);
        self::assertSame(['orillacart_customer', 'subscriber', 'author'], array_column($settings->availableRoles, 'name'));

        $saved = $this->shop->customers->updateSettings(new UpdateCustomerSettings('optional', roles: ['author', 'administrator']));
        self::assertSame(['orillacart_customer', 'author'], $saved->roles, 'the shop\'s own role stays, and a role the site does not offer is not taken');
        self::assertSame(['orillacart_customer', 'author'], $this->shop->customers->updateSettings(new UpdateCustomerSettings('required'))->roles, 'left as they are when not named');
    }
}
