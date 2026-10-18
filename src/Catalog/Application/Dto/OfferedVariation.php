<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * One way a product with variations is sold, as shoppers see it now.
 */
final readonly class OfferedVariation
{
    /**
     * @param list<int> $optionIds   in ascending order
     * @param string    $label       the option names, such as "M / Blue"
     * @param int|null  $available   null when stock does not limit it, as with backorders
     * @param bool      $onBackorder out of stock but sold on backorder, and the customer is to be told
     */
    public function __construct(
        public int $id,
        public array $optionIds,
        public string $label,
        public ?string $sku,
        public string $price,
        public ?string $regularPrice,
        public bool $forSale,
        public ?int $available,
        public bool $onBackorder = false,
    ) {}
}
