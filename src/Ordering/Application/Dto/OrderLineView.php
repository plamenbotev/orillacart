<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderLine;

final readonly class OrderLineView
{
    /**
     * @param list<array{attribute: string, option: string}> $options
     * @param list<int>                                    $optionIds
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public string $name,
        public ?string $sku,
        public array $options,
        public int $quantity,
        public string $unitPrice,
        public string $total,
        public string $tax,
        public array $optionIds = [],
        /** How many the shop owes; customer views show it only when backorderShown. */
        public int $backordered = 0,
        public bool $backorderShown = false,
        public int $refundedQuantity = 0,
        /** What coupons took off the total; the tax is on what is left. */
        public string $discount = '0',
        /** @var list<array{name: string, quantity: int}> what one bundle holds */
        public array $contents = [],
    ) {}

    public static function fromLine(OrderLine $line): self
    {
        return new self($line->productId(), $line->variationId(), $line->name(), $line->sku(), $line->options(), $line->quantity(), (string) $line->unitPrice(), (string) $line->total(), (string) $line->tax(), $line->optionIds(), $line->backordered(), $line->isBackorderShown(), $line->refundedQuantity(), (string) $line->discount(), $line->contents());
    }
}
