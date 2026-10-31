<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: add a price of its own to an order on hold, with no product: gift wrapping, a rush fee, so much each.
 */
final readonly class AddOrderCharge
{
    public function __construct(
        /** What the customer and the invoice read: "Gift wrapping". */
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $label,
        /** So much each, in the order's currency as the customer pays it (with tax when its prices include it). */
        #[Assert\Regex(pattern: '/^\d{1,11}(\.\d{1,4})?$/', message: 'Enter an amount such as 3.00.')]
        #[Assert\NotEqualTo(value: '0', message: 'A charge is for more than nothing.')]
        public string $amount,
        #[Assert\Range(min: 1, max: 9999)]
        public int $quantity = 1,
        /** Taxed as items of the tax group are; the shop's default group for none. */
        public bool $taxable = true,
        #[Assert\Positive]
        public ?int $taxGroupId = null,
    ) {}
}
