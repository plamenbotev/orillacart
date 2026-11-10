<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/** One item of an order an add-on places (API 22). */
final readonly class NewOrderItem
{
    /**
     * @param list<int>   $optionIds the options that pick a variation, when the product has them
     * @param string|null $unitPrice decimal, before tax: a price agreed instead of the catalogue's; null for the catalogue's
     */
    public function __construct(
        public int $productId,
        public int $quantity,
        public array $optionIds = [],
        public ?string $unitPrice = null,
    ) {}
}
