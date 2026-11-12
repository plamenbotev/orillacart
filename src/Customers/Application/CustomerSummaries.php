<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Application\Dto\CreateCustomer;
use OrillaCart\Customers\Application\Dto\CustomerQuery;
use OrillaCart\Customers\Application\Dto\SaveAddress;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Extension\Customer\Customers;
use OrillaCart\Extension\Customer\CustomerSummary;
use OrillaCart\Extension\Customer\NewCustomer;
use OrillaCart\Extension\Order\OrderAddress;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Validation\CommandChecks;

/** Customers as the extension API shows them to add-ons (design 61), made and found by them (API 27). */
final readonly class CustomerSummaries implements Customers
{
    public function __construct(private CustomerService $customers, private CommandChecks $checks, private Accounts $accounts) {}

    /**
     * The account as the shop makes one by hand (CustomerService::create): no sign-up email. Its addresses are
     * checked before anything is made, then saved in its address book: the billing one as where checkout starts
     * for billing, the shipping one for shipping (the billing one for both when only it is given).
     */
    public function create(NewCustomer $customer): CustomerSummary
    {
        $command = new CreateCustomer($customer->email, $customer->firstName, $customer->lastName, test: $customer->test);
        $addresses = [];
        if ($customer->billing !== null) {
            $addresses[] = new SaveAddress(self::data($customer->billing), true, $customer->shippingAddress === null);
        }
        if ($customer->shippingAddress !== null) {
            $addresses[] = new SaveAddress(self::data($customer->shippingAddress), $customer->billing === null, true);
        }
        $this->checks->check($command);
        foreach ($addresses as $address) {
            $this->checks->check($address);
        }
        try {
            $id = $this->customers->create($command)->id;
            foreach ($addresses as $address) {
                $this->customers->saveAddressOf($id, null, $address);
            }
        } catch (RuleViolation $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        return $this->find($id) ?? throw new \LogicException(\sprintf('The customer %d just made is not one of the shop\'s.', $id));
    }

    public function search(?string $email, ?string $words = null, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        if ($email !== null && trim($email) !== '') {
            $id = $this->accounts->idOf(mb_strtolower(trim($email)));
            $found = $id === null ? null : $this->find($id);

            return $found === null ? [] : [$found];
        }
        $page = $this->customers->customers(new CustomerQuery(search: $words, perPage: $limit));

        return array_values(array_filter(array_map(fn($view): ?CustomerSummary => $this->find($view->id), $page->customers)));
    }

    public function find(int $customerId): ?CustomerSummary
    {
        try {
            $customer = $this->customers->customer($customerId);
        } catch (CustomerNotFound) {
            return null;
        }

        $billing = $customer->billing;
        $shipping = $customer->shippingAddress;
        $fromOrder = null;
        if ($billing === null && $shipping === null && $customer->orders > 0) {
            // The account keeps no address, their orders do: the latest one's, and which order it is.
            $latest = $this->customers->latestOrderAddresses($customer->id);
            if ($latest !== null) {
                [$fromOrder, $billing, $shipping] = $latest;
            }
        }

        return new CustomerSummary(
            $customer->id,
            $customer->email,
            $customer->firstName,
            $customer->lastName,
            new \DateTimeImmutable($customer->registeredAt),
            $billing === null ? null : self::address($billing),
            $shipping === null ? null : self::address($shipping),
            $customer->groupIds,
            $customer->orders,
            $fromOrder,
        );
    }

    private static function data(OrderAddress $address): AddressData
    {
        return new AddressData($address->firstName, $address->lastName, $address->street, $address->city, $address->postcode, $address->country, $address->phone, $address->state);
    }

    private static function address(AddressData $address): OrderAddress
    {
        return new OrderAddress($address->firstName, $address->lastName, $address->street, $address->city, $address->postcode, $address->country, $address->state, $address->phone);
    }
}
