<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

/**
 * What an order line is: an item sold, an item a discount gave, an item an add-on kept in the cart, a charge (a
 * price of its own, with no product), the shipping, or a fee. Each is its own kind of
 * line (OrderLine::of()), stored in one table by this value.
 */
enum LineType: string
{
    case Item = 'item';
    case Given = 'given';
    case AddOn = 'add_on';
    case Charge = 'charge';
    case Shipping = 'shipping';
    case Fee = 'fee';

    /** A product the order holds: sold, or given with it. */
    public function isItem(): bool
    {
        return $this === self::Item || $this === self::Given || $this === self::AddOn;
    }

    /** What tax services and reports tell lines apart by: "item" (sold or given), "shipping" or "fee" (a charge too). */
    public function kind(): string
    {
        return match (true) {
            $this->isItem() => 'item',
            $this === self::Charge => 'fee',
            default => $this->value,
        };
    }

    /** Refunded so many at a time: the items, and the charges. */
    public function byQuantity(): bool
    {
        return $this->isItem() || $this === self::Charge;
    }

    /** Where it goes among the lines: the items first (refunds and downloads name them by place), then charges, shipping and fees. */
    public function rank(): int
    {
        return match ($this) {
            self::Item, self::Given, self::AddOn => 0,
            self::Charge => 1,
            self::Shipping => 2,
            self::Fee => 3,
        };
    }
}
