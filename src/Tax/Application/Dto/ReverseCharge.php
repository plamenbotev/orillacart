<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

/**
 * Whether a business customer's order is sold without VAT, the customer accounting for it (reverse charge), and
 * what to tell them.
 */
final readonly class ReverseCharge
{
    public function __construct(
        public bool $applies,
        /** The VAT number as checked, prefix first; null when none was given. */
        public ?string $number = null,
        /** Why VAT is charged although a number was given; null when there is nothing to say. */
        public ?string $message = null,
    ) {}

    public static function none(): self
    {
        return new self(false);
    }
}
