<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

enum ProductType: string
{
    /** Shipped to the customer; weight and size count for shipping. */
    case Physical = 'physical';
    /** Nothing to ship, such as a service or a booking. */
    case Virtual = 'virtual';

    public function needsShipping(): bool
    {
        return $this === self::Physical;
    }
}
