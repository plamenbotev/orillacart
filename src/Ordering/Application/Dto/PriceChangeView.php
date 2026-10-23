<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderLine;
use OrillaCart\Ordering\Domain\PriceAdjustment;
use OrillaCart\Shared\Domain\Money;

/**
 * One change the shop or an add-on made to an order's price, once however many lines it was spread over: its
 * total, and the item it is for, or none for the whole order.
 */
final readonly class PriceChangeView
{
    public function __construct(
        /** Its key, to take it off by. */
        public string $change,
        /** "manual", "add_on" or "adjuster". */
        public string $kind,
        /** The shop manager's user id, or the add-on's id. */
        public string $source,
        public string $label,
        /** Decimal, below zero when it lowered the price: what all its shares add up to. */
        public string $amount,
        /** The item's place among the order's items; null for the whole order. */
        public ?int $line,
        public ?string $reason,
        /** The shop can take it off; an adjuster's is worked out again whenever the order is priced. */
        public bool $removable,
    ) {}

    /**
     * @param list<OrderLine> $items the order's items, in their order
     *
     * @return list<self> in the order they were made
     */
    public static function of(array $items): array
    {
        /** @var array<string, array{row: int, adjustment: PriceAdjustment, total: Money, line: ?int}> $changes */
        $changes = [];
        foreach ($items as $place => $line) {
            foreach ($line->adjustments() as $row) {
                $adjustment = $row->adjustment();
                if ($adjustment->change === null || $adjustment->isDiscount()) {
                    continue;
                }
                $known = $changes[$adjustment->change] ?? null;
                $changes[$adjustment->change] = [
                    'row' => min($known['row'] ?? \PHP_INT_MAX, $row->id() ?? \PHP_INT_MAX),
                    'adjustment' => $adjustment,
                    'total' => ($known['total'] ?? Money::zero())->add($adjustment->amount),
                    'line' => $adjustment->scope === PriceAdjustment::LINE ? $place : null,
                ];
            }
        }
        uasort($changes, static fn(array $a, array $b): int => $a['row'] <=> $b['row']);

        return array_values(array_map(static fn(string $key, array $change): self => new self(
            $key,
            $change['adjustment']->kind,
            $change['adjustment']->source,
            $change['adjustment']->label,
            (string) $change['total'],
            $change['line'],
            $change['adjustment']->reason,
            \in_array($change['adjustment']->kind, PriceAdjustment::KEPT, true),
        ), array_keys($changes), $changes));
    }
}
