<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Application\Dto\CustomerPageView;
use OrillaCart\Customers\Application\Dto\CustomerQuery;
use OrillaCart\Customers\Application\Dto\CustomerSettingsView;
use OrillaCart\Customers\Application\Dto\CustomerView;
use OrillaCart\Customers\Application\Dto\RegisterAccount;
use OrillaCart\Customers\Application\Dto\SaveAddresses;
use OrillaCart\Customers\Application\Dto\UpdateCustomerSettings;
use OrillaCart\Customers\Domain\AccountExists;
use OrillaCart\Customers\Domain\AccountMode;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Customers\Domain\CustomerSettings;
use OrillaCart\Customers\Domain\CustomerSettingsRepository;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * Customer accounts: whether the shop has them, making one at checkout, and the addresses checkout fills in.
 */
final readonly class CustomerService
{
    public function __construct(
        private Accounts $accounts,
        private CustomerSettingsRepository $settings,
        private OrderService $orders,
    ) {}

    public function settings(): CustomerSettingsView
    {
        return new CustomerSettingsView($this->settings->load()->accounts->value, AccountMode::values());
    }

    public function updateSettings(UpdateCustomerSettings $command): CustomerSettingsView
    {
        $this->settings->save(new CustomerSettings(AccountMode::from($command->accounts)));

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

        return CustomerView::fromRecord($record, $this->orders->orders(new OrderQuery(customerId: $id, perPage: 1))->total);
    }

    public function customers(CustomerQuery $query): CustomerPageView
    {
        $search = $query->search === null || trim($query->search) === '' ? null : trim($query->search);
        [$records, $total] = $this->accounts->search($search, ($query->page - 1) * $query->perPage, $query->perPage);

        return new CustomerPageView(
            array_map(fn($record): CustomerView => CustomerView::fromRecord($record, $this->orders->orders(new OrderQuery(customerId: $record->id, perPage: 1))->total), $records),
            $total,
            $query->page,
            max(1, (int) ceil($total / $query->perPage)),
        );
    }

    /**
     * @throws CustomerNotFound
     */
    public function saveAddresses(int $id, SaveAddresses $command): CustomerView
    {
        $this->accounts->find($id) ?? throw new CustomerNotFound($id);
        $this->accounts->saveAddresses($id, $command->billing, $command->shippingAddress);

        return $this->customer($id);
    }

    /**
     * Makes a visitor's account and logs them in.
     *
     * @throws AccountExists
     * @throws RuleViolation when the shop makes no accounts, or for a password that is too short
     */
    public function register(RegisterAccount $command): CustomerView
    {
        if (!$this->accountsOffered()) {
            throw new RuleViolation('The shop does not make accounts.', 'orillacart_accounts_disabled');
        }
        $this->checkRegistration($command->email, $command->password);
        $id = $this->accounts->register($command->email, $command->password, $command->firstName, $command->lastName);
        $this->accounts->logIn($id);

        return $this->customer($id);
    }

    /**
     * Refuses an account checkout could not make, before the order is placed.
     *
     * @throws AccountExists
     * @throws RuleViolation for a password that is too short
     */
    public function checkRegistration(string $email, string $password): void
    {
        if (mb_strlen($password) < CustomerSettings::MIN_PASSWORD_LENGTH) {
            throw new RuleViolation(\sprintf('Choose a password of at least %d characters.', CustomerSettings::MIN_PASSWORD_LENGTH), 'orillacart_weak_password');
        }
        if ($this->accounts->isRegistered($email)) {
            throw new AccountExists();
        }
    }

    /**
     * Makes the account of a customer who just ordered, gives them the order and logs them in.
     *
     * @throws AccountExists
     * @throws RuleViolation
     */
    public function registerAfterOrder(int $orderId, string $email, string $password, AddressData $billing, ?AddressData $shippingAddress): int
    {
        $this->checkRegistration($email, $password);
        $id = $this->accounts->register($email, $password, $billing->firstName, $billing->lastName);
        $this->accounts->saveAddresses($id, $billing, $shippingAddress);
        $this->orders->giveTo($orderId, $id);
        $this->accounts->logIn($id);

        return $id;
    }

    /**
     * Remembers the addresses a logged-in customer ordered with, for their next order.
     */
    public function rememberAddresses(int $id, AddressData $billing, ?AddressData $shippingAddress): void
    {
        if ($this->accounts->find($id) !== null) {
            $this->accounts->saveAddresses($id, $billing, $shippingAddress);
        }
    }
}
