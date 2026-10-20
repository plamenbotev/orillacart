<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

/**
 * Why a stock level moved.
 */
enum StockReason: string
{
    /** Taken for an order. */
    case Sale = 'sale';
    /** Given back as an order closed unpaid, was cancelled or changed. */
    case Release = 'release';
    /** Refunded items put back in stock. */
    case Return = 'return';
    /** Counted: the shop set the level on the stock screen or the product. */
    case Count = 'count';
    case Import = 'import';
    case BulkEdit = 'bulk_edit';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
