<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderLine;

final readonly class OrderLineView
{
    /**
     * @param list<array{attribute: string, option: string}> $options
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
    ) {}

    public static function fromLine(OrderLine $line): self
    {
        return new self($line->productId(), $line->variationId(), $line->name(), $line->sku(), $line->options(), $line->quantity(), (string) $line->unitPrice(), (string) $line->total(), (string) $line->tax());
    }
}
