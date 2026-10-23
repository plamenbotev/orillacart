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
     * @param string   $backorders     no, yes or notify, as the item says
     * @param int|null $backorderLimit how many the item may owe at most; null for no limit
     * @param string   $price          what the item costs inside one bundle (its unit price times its quantity), for
     *                                 sharing the bundle's price out when it is taxed per item
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public string $name,
        public int $quantity,
        public bool $countsStock,
        public string $backorders = 'no',
        public ?int $backorderLimit = null,
        public string $price = '0',
        public bool $taxable = true,
        public ?int $taxGroupId = null,
        /** What tax services call it; null for its tax group's code. */
        public ?string $taxCode = null,
    ) {}
}
