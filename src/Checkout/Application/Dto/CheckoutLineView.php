<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

/**
 * A line of the order being placed: prices before tax, and its tax (exact; the totals round per tax rate).
 */
final readonly class CheckoutLineView
{
    /**
     * @param list<array{attribute: string, option: string}> $options
     */
    public function __construct(
        public string $name,
        public array $options,
        public int $quantity,
        public string $unitPrice,
        public string $total,
        public string $tax,
    ) {}
}
