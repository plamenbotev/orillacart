<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Customer;

use OrillaCart\Extension\Order\OrderAddress;

/**
 * A customer as add-ons may read them (API 22): who they are, their saved addresses and how often they ordered.
 * Nothing of their password or login.
 */
final readonly class CustomerSummary
{
    /**
     * @param list<int> $groupIds the customer groups they are in
     * @param int       $orders   how many orders they placed
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
    ) {}
}
