<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

/**
 * A cart line priced at the catalog's current prices, before tax. A line with a problem cannot be ordered
 * until the customer changes or removes it: "unavailable" (no longer sold), "insufficient_stock", or "refused"
 * by an add-on's cart rule, whose message says why.
 */
final readonly class CartLineView
{
    public const string UNAVAILABLE = 'unavailable';
    public const string INSUFFICIENT_STOCK = 'insufficient_stock';
    public const string REFUSED = 'refused';

    /**
     * @param list<array{attribute: string, option: string}> $options
     * @param int $backordered how many of the quantity are on backorder, when the customer is to be told
     */
    public function __construct(
        public int $id,
        public int $productId,
        public ?int $variationId,
        public string $name,
        public ?string $sku,
        public array $options,
        public int $quantity,
        public string $unitPrice,
        public string $total,
        public ?int $available,
        public ?string $problem,
        public int $backordered = 0,
        /** @var list<array{name: string, quantity: int}> what one bundle holds */
        public array $contents = [],
        /** Why a cart rule refuses the line, for the customer. */
        public ?string $message = null,
    ) {}
}
