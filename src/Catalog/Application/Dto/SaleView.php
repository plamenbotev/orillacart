<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\Sale;

final readonly class SaleView
{
    public function __construct(
        public string $price,
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $until,
    ) {}

    public static function fromSale(?Sale $sale): ?self
    {
        return $sale === null ? null : new self((string) $sale->price, $sale->from, $sale->until);
    }
}
