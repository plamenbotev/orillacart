<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

final class InsufficientStock extends RuleViolation
{
    public function __construct(public readonly StockItem $item, public readonly int $requested, public readonly int $available)
    {
        parent::__construct(
            \sprintf('Only %d of %s are in stock, %d were asked for.', $available, $item, $requested),
            'orillacart_insufficient_stock',
        );
    }
}
