<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Ordering\Domain\PriceAdjustment;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: change the price of an order on hold, or of one of its items, by an amount: below zero to lower it.
 */
final readonly class AdjustOrderPrice
{
    public function __construct(
        #[Assert\Regex(pattern: '/^-?\d{1,11}(\.\d{1,4})?$/', message: 'Enter an amount such as -5.00 or 2.50.')]
        #[Assert\NotEqualTo(value: '0', message: 'A change is for more or less than nothing.')]
        public string $amount,
        /** What the customer and the invoice see: "Loyal customer", "Damaged box". */
        #[Assert\NotBlank]
        #[Assert\Length(max: PriceAdjustment::MAX_LABEL_LENGTH)]
        public string $label,
        /** The item's place among the order's items, from 0; null for the whole order, spread over its items. */
        #[Assert\PositiveOrZero]
        public ?int $line = null,
        /** Why, for the shop's records. */
        #[Assert\Length(max: 200)]
        public string $reason = '',
    ) {}
}
