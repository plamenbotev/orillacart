<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * What happens when a product whose stock is counted runs out: it stops selling, or it keeps selling and the
 * shop owes what it did not have, telling the customer or not.
 */
enum Backorders: string
{
    case No = 'no';
    /** Keeps selling; the customer is not told that part of the order is owed. */
    case Yes = 'yes';
    /** Keeps selling, and the product page, cart, receipt and emails say what is on backorder. */
    case Notify = 'notify';

    public function allowed(): bool
    {
        return $this !== self::No;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $backorders): string => $backorders->value, self::cases());
    }
}
