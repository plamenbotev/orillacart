<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * Command: what an order on hold contains now, as the shop repriced it.
 */
final readonly class NewOrderItems
{
    /**
     * @param list<NewOrderLine> $lines the items, the shipping and the fees, priced again
     */
    public function __construct(
        public array $lines,
        public ?NewShipping $shipping,
        public ?AddressData $shippingAddress,
        public string $total,
        public bool $pricesIncludeTax = false,
    ) {}
}
