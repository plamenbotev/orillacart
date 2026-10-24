<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Application\Dto\ChooseEmails;
use OrillaCart\Customers\Application\Dto\ConfirmAccount;
use OrillaCart\Customers\Application\Dto\CustomerPageView;
use OrillaCart\Customers\Application\Dto\CustomerQuery;
use OrillaCart\Customers\Application\Dto\CustomerSettingsView;
use OrillaCart\Customers\Application\Dto\CustomerView;
use OrillaCart\Customers\Application\Dto\RegisterAccount;
use OrillaCart\Customers\Application\Dto\SaveAddress;
use OrillaCart\Customers\Application\Dto\UpdateCustomerSettings;
use OrillaCart\Customers\Domain\AccountMode;
use OrillaCart\Customers\Domain\CustomerGroupRepository;
use OrillaCart\Customers\Domain\CustomerNoteRepository;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Customers\Domain\CustomerRuleViolation;
use OrillaCart\Customers\Domain\CustomerSettings;
use OrillaCart\Customers\Domain\CustomerSettingsRepository;
use OrillaCart\Customers\Domain\Event\CustomerDeleted;
use OrillaCart\Customers\Domain\Event\CustomerErased;
use OrillaCart\Customers\Domain\PendingAccount;
use OrillaCart\Customers\Domain\PendingAccountRepository;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Newsletter\Domain\SignUpSource;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\LifetimeValueView;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\RuleViolation;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Customer accounts: whether the shop has them, making one (by a link sent to the email), and the address book
 * checkout fills addresses in from.
 */
final readonly class CustomerService
{
    public function __construct(
        private Accounts $accounts,
        private CustomerSettingsRepository $settings,
        private OrderService $orders,
        private CustomerGroupRepository $groups,
        private EventDispatcherInterface $events,
        private PendingAccountRepository $pending,
        private AccountMail $accountMail,
        private AccountLinks $links,
        private ClockInterface $clock,
        private SettingsService $shop,
        private CustomerNoteRepository $notes,
        private Newsletter $newsletter,
    ) {}

    /**
     * The person of the email erased, as a privacy request asks: their saved addresses, wishlist, cart-reminder
     * choice and the shop's notes about them go, and they leave their groups. The WordPress account itself is
     * WordPress's to remove.
     *
     * @return bool whether there was anything to erase
     */
    public function erase(string $email): bool
    {
        // An account asked for and not made holds the email and names too.
        $pending = $this->pending->findByEmail($email);
        if ($pending !== null) {
            $this->pending->remove($pending);
        }
        $id = $this->accounts->idOf($email);
        $record = $id === null ? null : $this->accounts->find($id);
        if ($id === null || $record === null) {
            return $pending !== null;
        }
        $groupIds = $this->groups->groupIdsOf($id);
        $noted = $this->notes->ofCustomer($id) !== [];
        $wished = $this->accounts->wishlist($id) !== [];
        $this->accounts->saveAddresses($id, new AddressBook());
        $this->accounts->saveWishlist($id, []);
        $this->accounts->saveCartReminders($id, true);
        $this->notes->removeOfCustomer($id);
        foreach ($groupIds as $groupId) {
            $group = $this->groups->find($groupId);
            if ($group !== null) {
                $group->remove($id);
                $this->groups->save($group);
            }
        }
        $this->events->dispatch(new CustomerErased($id));

        return $record->addresses->addresses !== [] || $groupIds !== [] || $noted || $wished;
    }

    /** An account the email asked for and has not confirmed: the names given, for the person's export. */
    public function pendingAccount(string $email): ?string
    {
        $pending = $this->pending->findByEmail($email);

        return $pending === null ? null : trim($pending->firstName() . ' ' . $pending->lastName());
    }

    /** The account of the email, if any. */
    public function customerIdOf(string $email): ?int
    {
        return $this->accounts->idOf($email);
    }

    /**
     * A customer's account was deleted: the shop's notes about them go, they leave their groups, and the other areas let go of them too (their
     * orders become guest orders, their carts go).
     */
    public function forget(int $customerId): void
    {
        $this->notes->removeOfCustomer($customerId);
        foreach ($this->groups->groupIdsOf($customerId) as $groupId) {
            $group = $this->groups->find($groupId);
            if ($group !== null) {
                $group->remove($customerId);
                $this->groups->save($group);
            }
        }
        $this->events->dispatch(new CustomerDeleted($customerId));
    }

    public function settings(): CustomerSettingsView
    {
        $settings = $this->settings->load();

        return new CustomerSettingsView($settings->accounts->value, AccountMode::values(), $settings->guestOrders);
    }

    public function updateSettings(UpdateCustomerSettings $command): CustomerSettingsView
    {
        $this->settings->save(new CustomerSettings(AccountMode::from($command->accounts), $command->guestOrders ?? $this->settings->load()->guestOrders));

        return $this->settings();
    }

    /** "optional", "required" or "disabled". */
    public function accountMode(): string
    {
        return $this->settings->load()->accounts->value;
    }

    public function accountsRequired(): bool
    {
        return $this->settings->load()->accounts === AccountMode::Required;
    }

    public function accountsOffered(): bool
    {
        return $this->settings->load()->accounts !== AccountMode::Disabled;
    }

    /**
     * @throws CustomerNotFound
     */
    public function customer(int $id): CustomerView
    {
        $record = $this->accounts->find($id) ?? throw new CustomerNotFound($id);

        return CustomerView::fromRecord($record, $this->orders->orders(new OrderQuery(customerId: $id, perPage: 1))->total, $this->groups->groupIdsOf($id), $this->lifetimeValues([$id])[$id], $this->newsletter->subscription($record->email));
    }

    public function customers(CustomerQuery $query): CustomerPageView
    {
        $search = $query->search === null || trim($query->search) === '' ? null : trim($query->search);
        [$records, $total] = $this->accounts->search($search, ($query->page - 1) * $query->perPage, $query->perPage);
        // Three queries for the page, not three a customer.
        $ids = array_map(static fn($record): int => $record->id, $records);
        $orders = $this->orders->orderCounts($ids);
        $groups = $this->groups->groupIdsOfMany($ids);
        $values = $this->lifetimeValues($ids);

        return new CustomerPageView(
            array_map(static fn($record): CustomerView => CustomerView::fromRecord($record, $orders[$record->id] ?? 0, $groups[$record->id] ?? [], $values[$record->id] ?? null), $records),
            $total,
            $query->page,
            max(1, (int) ceil($total / $query->perPage)),
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, LifetimeValueView>
     */
    private function lifetimeValues(array $ids): array
    {
        $shop = $this->shop->current();

        return $this->orders->lifetimeValues($ids, $shop->currency, $shop->priceDecimals);
    }

    /** Where a customer has things sent, as they saved it: their shipping address, or their billing one. */
    public function savedAddress(int $id): ?AddressData
    {
        return $this->accounts->find($id)?->addresses->shipping();
    }

    /**
     * Saves an address in the customer's address book: a new one, or in place of the one of the id.
     *
     * @throws CustomerNotFound
     * @throws CustomerRuleViolation for an id the book does not have, or a full book
     */
    public function saveAddress(int $id, ?string $addressId, SaveAddress $command): CustomerView
    {
        $record = $this->accounts->find($id) ?? throw new CustomerNotFound($id);
        [$book] = $record->addresses->with($addressId, $command->address, $command->billing, $command->shipping);
        $this->accounts->saveAddresses($id, $book);

        return $this->customer($id);
    }

    /**
     * @throws CustomerNotFound
     * @throws CustomerRuleViolation for an id the book does not have
     */
    public function removeAddress(int $id, string $addressId): CustomerView
    {
        $record = $this->accounts->find($id) ?? throw new CustomerNotFound($id);
        $this->accounts->saveAddresses($id, $record->addresses->without($addressId));

        return $this->customer($id);
    }

    /**
     * A visitor asks for an account, on the account page or with an order: the link to make it goes to the email,
     * or word that it has one already. Whoever asked learns nothing either way, so nobody can find out from the
     * shop whose email is a customer's. Asking again sends a new link; the old one stops working.
     *
     * @param int|null $orderId the order placed while asking, for the account to have
     *
     * @throws RuleViolation when the shop makes no accounts
     */
    public function requestAccount(RegisterAccount $command, ?int $orderId = null): void
    {
        if (!$this->accountsOffered()) {
            throw new RuleViolation('The shop does not make accounts.', 'orillacart_accounts_disabled');
        }
        if ($this->accounts->isRegistered($command->email)) {
            $this->accountMail->exists($command->email, $this->links->loginUrl(), $this->links->lostPasswordUrl());

            return;
        }
        $earlier = $this->pending->findByEmail($command->email);
        $orderIds = [...($earlier?->orderIds() ?? []), ...($orderId === null ? [] : [$orderId])];
        if ($earlier !== null) {
            $this->pending->remove($earlier);
        }
        [$account, $token] = PendingAccount::start($command->email, $command->firstName, $command->lastName, $orderIds, $this->clock->now());
        $this->pending->save($account);
        $this->accountMail->confirm($account->email(), $account->firstName(), $this->links->confirmUrl($token), $account->expiresAt(), $this->settings->load()->guestOrders);
    }

    /**
     * The visitor followed the link and chose a password: the account is made, with the orders placed while
     * asking for it (and, as the shop chooses, the earlier guest orders of the email) and the addresses of the
     * latest placed while asking, or else of the latest guest order; and the browser is logged in to it.
     *
     * @throws RuleViolation for a link that does not work (any more), a password that is too short, or an email
     *                       that has an account since
     */
    public function confirmAccount(ConfirmAccount $command): CustomerView
    {
        $account = $this->pending->findByToken($command->token);
        if ($account === null || $account->isExpired($this->clock->now())) {
            throw new RuleViolation('This link does not work any more. Sign up again for a new one.', 'orillacart_invalid_link');
        }
        if (mb_strlen($command->password) < CustomerSettings::MIN_PASSWORD_LENGTH) {
            throw new RuleViolation('Choose a password of at least %d characters.', 'orillacart_weak_password', values: [CustomerSettings::MIN_PASSWORD_LENGTH]);
        }
        if ($this->accounts->isRegistered($account->email())) {
            $this->pending->remove($account);

            throw new RuleViolation('This email has an account: log in instead.', 'orillacart_account_exists');
        }
        $id = $this->accounts->register($account->email(), $command->password, $account->firstName(), $account->lastName());
        // The addresses the customer gave last: an order of years ago may be from a home they left.
        $addressedBy = $account->orderIds() !== [] ? max($account->orderIds()) : null;
        $addressed = false;
        // The link came to the email, so its owner made the account: the orders placed with it as a guest join
        // it, the ones placed while asking first, as the confirmation email says; unless the shop chose only those.
        $guestOrders = $this->settings->load()->guestOrders ? $this->orders->guestOrdersOf($account->email()) : [];
        $addressedBy ??= $guestOrders === [] ? null : max($guestOrders);
        foreach (array_values(array_unique([...$account->orderIds(), ...$guestOrders])) as $orderId) {
            try {
                $order = $this->orders->order($orderId);
                $this->orders->giveTo($orderId, $id);
            } catch (OrderNotFound) {
                continue;
            }
            if (!$addressed && $orderId === $addressedBy) {
                $this->accounts->saveAddresses($id, new AddressBook()->remembering($order->billing, $order->shippingAddress));
                $addressed = true;
            }
        }
        $this->pending->remove($account);
        $this->accounts->logIn($id);
        $this->accountMail->welcome($account->email(), $account->firstName(), $this->links->accountUrl());

        return $this->customer($id);
    }

    /** Forgets the accounts asked for whose links stopped working, daily. */
    public function forgetUnconfirmed(): void
    {
        $this->pending->removeExpired($this->clock->now());
    }

    /**
     * The emails a customer chooses in their account: the newsletter (when the shop has one), and whether
     * checkout starts with the box ticked to remind them of a cart they leave.
     *
     * @throws CustomerNotFound
     */
    public function chooseEmails(int $id, ChooseEmails $command): CustomerView
    {
        $record = $this->accounts->find($id) ?? throw new CustomerNotFound($id);
        $this->accounts->saveCartReminders($id, $command->cartReminders);
        if ($command->newsletter === true && $this->newsletter->offer() !== null) {
            $this->newsletter->subscribe($record->email, $record->firstName, $record->lastName, SignUpSource::Account, $id);
        } elseif ($command->newsletter === false) {
            $this->newsletter->unsubscribe($record->email);
        }

        return $this->customer($id);
    }

    /**
     * Moves an order to a customer's account, or makes it a guest's order.
     *
     * @throws CustomerNotFound for an account that does not exist
     * @throws OrderNotFound
     */
    public function assignOrder(int $orderId, ?int $customerId, ?int $userId): OrderView
    {
        if ($customerId !== null) {
            $this->accounts->find($customerId) ?? throw new CustomerNotFound($customerId);
        }

        return $this->orders->changeCustomer($orderId, $customerId, $userId);
    }

    /**
     * Remembers the addresses a logged-in customer ordered with in their address book, as the ones to start
     * from next time.
     */
    public function rememberAddresses(int $id, AddressData $billing, ?AddressData $shippingAddress): void
    {
        $record = $this->accounts->find($id);
        if ($record !== null) {
            $this->accounts->saveAddresses($id, $record->addresses->remembering($billing, $shippingAddress));
        }
    }
}
