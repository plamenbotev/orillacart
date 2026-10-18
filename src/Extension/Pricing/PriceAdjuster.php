<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Pricing;

/**
 * Changes what a product costs, after the shop's own price rules: a wholesale price list, a surcharge, a price from
 * an ERP. Everything that shows or charges a price asks it (product page, listings, cart, checkout, order editing);
 * options and bundles then price on top as usual. Adjusters run in the order they were registered, each on the
 * last one's price.
 *
 * The search index keeps the price a guest sees, as for price rules. An adjuster whose answer changes with time
 * (not only with the product, quantity and customer) has to have the products reindexed when it does.
 */
interface PriceAdjuster
{
    /** Lowercase letters, digits and underscores, up to 40; unique among adjusters. */
    public function id(): string;

    /**
     * @return string|null the unit price as a decimal number ("12.50"), or null to keep $query->price; a price below
     *                     zero is taken as zero, and an answer that is not a number is logged and ignored
     */
    public function adjust(PriceQuery $query): ?string;
}
