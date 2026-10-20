<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * Command: change many products at once, as the products list's bulk edit does. Each change is left out with
 * null.
 *
 * @param string|null $price set (to the amount), raise or lower (by it), raise_percent or lower_percent (by it)
 * @param string|null $sale  set (to the amount), percent_off (the price less it), or remove
 * @param string|null $stock set (the stockroom's level to the number) or add (it, below zero to take)
 */
final readonly class BulkEdit
{
    public const array PRICES = ['set', 'raise', 'lower', 'raise_percent', 'lower_percent'];
    public const array SALES = ['set', 'percent_off', 'remove'];
    public const array STOCKS = ['set', 'add'];

    public function __construct(
        public ?string $price = null,
        public string $priceBy = '',
        public ?string $sale = null,
        public string $saleBy = '',
        public ?string $stock = null,
        public ?int $stockroomId = null,
        public int $stockBy = 0,
    ) {}
}
