<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Inventory\Application\Dto\TakenStock;

/**
 * An order line as checkout priced it. Amounts are decimal strings: the unit price and line total before tax,
 * and the line's tax.
 */
final readonly class NewOrderLine
{
    /**
     * @param list<int>                                    $optionIds
     * @param list<array{attribute: string, option: string}> $options
     * @param list<TakenStock>                             $taken
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public array $optionIds,
        public array $options,
        public string $name,
        public ?string $sku,
        public int $quantity,
        public string $unitPrice,
        public string $total,
        public string $tax,
        public bool $ships,
        public array $taken,
    ) {}
}
