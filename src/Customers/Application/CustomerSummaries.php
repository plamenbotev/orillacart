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

    private static function address(AddressData $address): OrderAddress
    {
        return new OrderAddress($address->firstName, $address->lastName, $address->street, $address->city, $address->postcode, $address->country, $address->state, $address->phone);
    }
}
