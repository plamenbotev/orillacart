<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Extension\Customer\Customers;
use OrillaCart\Extension\Customer\CustomerSummary;
use OrillaCart\Extension\Order\OrderAddress;
use OrillaCart\Ordering\Application\Dto\AddressData;

/** Customers as the extension API shows them to add-ons (design 61). */
final readonly class CustomerSummaries implements Customers
{
    public function __construct(private CustomerService $customers) {}

    public function find(int $customerId): ?CustomerSummary
    {
        try {
            $customer = $this->customers->customer($customerId);
        } catch (CustomerNotFound) {
            return null;
        }

        return new CustomerSummary(
            $customer->id,
            $customer->email,
            $customer->firstName,
            $customer->lastName,
            new \DateTimeImmutable($customer->registeredAt),
            $customer->billing === null ? null : self::address($customer->billing),
            $customer->shippingAddress === null ? null : self::address($customer->shippingAddress),
            $customer->groupIds,
            $customer->orders,
        );
    }

    private static function address(AddressData $address): OrderAddress
    {
        return new OrderAddress($address->firstName, $address->lastName, $address->street, $address->city, $address->postcode, $address->country, $address->state, $address->phone);
    }
}
