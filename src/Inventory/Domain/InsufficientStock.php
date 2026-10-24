<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

final class InsufficientStock extends RuleViolation
{
    public function __construct(public readonly StockItem $item, public readonly int $requested, public readonly int $available)
    {
        parent::__construct(
            'Only %1$d of %2$s are in stock, %3$d were asked for.',
            'orillacart_insufficient_stock',
            values: [$available, $item, $requested],
        );
    }
}
