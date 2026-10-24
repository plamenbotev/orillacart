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
        public bool $pricesIncludeTax,
        /** @var array{reason: string, certificate: string}|null the exemption the prices went by */
        public ?array $taxExemption,
        /** The tax service that worked out the taxes; null for the shop's own rates. */
        public ?string $taxProvider,
    ) {}
}
