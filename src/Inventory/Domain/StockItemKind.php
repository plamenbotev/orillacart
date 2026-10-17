<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

enum StockItemKind: string
{
    case Product = 'product';
    case Variation = 'variation';
    /** An attribute option, such as a gift wrap the shop has a limited number of. */
    case Option = 'option';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $kind): string => $kind->value, self::cases());
    }
}
