<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * An item inside a quoted bundle: how many of it one bundle holds, and how its stock is taken.
 */
final readonly class QuotedBundleItem
{
    /**
     * @param bool   $countsStock whether selling it takes stock: its stock is counted
     * @param string $backorders  no, yes or notify, as the item says
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public string $name,
        public int $quantity,
        public bool $countsStock,
        public string $backorders = 'no',
    ) {}
}
