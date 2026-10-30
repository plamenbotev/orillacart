<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * A save set levels from what it loaded, and some of them moved since (a sale, a return, another person's save):
 * nothing was saved. The cells name each changed level as it is now, to show before saving again.
 */
final class StockChangedMeanwhile extends RuleViolation
{
    /**
     * @param list<array{kind: string, id: int, stockroom_id: int, quantity: ?int}> $cells quantity null: no level
     */
    public function __construct(public readonly array $cells)
    {
        parent::__construct('The stock changed since it was loaded: sales or another save moved it. Check the levels shown and save again.', 'orillacart_stock_changed');
    }
}
