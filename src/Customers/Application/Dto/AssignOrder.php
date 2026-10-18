<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the customer an order belongs to; null makes it a guest's order.
 */
final readonly class AssignOrder
{
    public function __construct(
        #[Assert\Positive]
        public ?int $customerId = null,
    ) {}
}
