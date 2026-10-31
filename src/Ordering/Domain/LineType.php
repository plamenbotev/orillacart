<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

/**
 * What an order line is: an item sold, an item a discount gave, the shipping, or a fee. Each is its own kind of
 * line (OrderLine::of()), stored in one table by this value.
 */
enum LineType: string
{
    case Item = 'item';
    case Given = 'given';
    case Shipping = 'shipping';
    case Fee = 'fee';

    /** A product the order holds: sold, or given with it. */
    public function isItem(): bool
    {
        return $this === self::Item || $this === self::Given;
    }

    /** What tax services and reports tell lines apart by: "item" (sold or given), "shipping" or "fee". */
    public function kind(): string
    {
        return $this->isItem() ? 'item' : $this->value;
    }

    /** Where it goes among the lines: the items first (refunds and downloads name them by place), then shipping, then fees. */
    public function rank(): int
    {
        return match ($this) {
            self::Item, self::Given => 0,
            self::Shipping => 1,
            self::Fee => 2,
        };
    }
}
