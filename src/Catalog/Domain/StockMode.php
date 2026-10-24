<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * Whether stock limits how many of a product or variation can be sold.
 */
enum StockMode: string
{
    /**
     * The stock levels in the stockrooms decide. An item with no stock levels at all is not limited; one saved in
     * a shop with stockrooms starts at 0 in one of them (StartCountingStock).
     */
    case Tracked = 'tracked';
    /** Always for sale, whatever the stockrooms hold. */
    case AlwaysAvailable = 'always_available';
    /** Shown, but not for sale until the mode changes. */
    case Unavailable = 'unavailable';
}
