<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Customer;

use OrillaCart\Extension\Order\OrderAddress;

/**
 * A customer as add-ons may read them (API 22): who they are, their saved addresses (or, where the account keeps
 * none, their latest order's) and how often they ordered.
 * Nothing of their password or login.
 */
final readonly class CustomerSummary
{
    /**
     * @param list<int> $groupIds the customer groups they are in
     * @param int       $orders   how many orders they placed
     * @param int|null  $addressesFromOrder the order whose addresses these are, by number, where the account keeps
     *                                      none and their latest order has them; null when they are the account's
     *                                      (API 23)
     */
    public function __construct(
        public int $id,
        public string $email,
        public string $firstName,
        public string $lastName,
        public \DateTimeImmutable $registeredAt,
        public ?OrderAddress $billing,
        public ?OrderAddress $shippingAddress,
        public array $groupIds,
        public int $orders,
        public ?int $addressesFromOrder = null,
    ) {}
}
