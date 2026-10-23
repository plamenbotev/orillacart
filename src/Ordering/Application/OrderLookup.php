<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

/**
 * Finds orders by text an area keeps about them: a shipment's tracking number, an invoice's number, a coupon's
 * code. The shop's order search asks each. Tagged by the Kernel, so the container finds every one.
 */
interface OrderLookup
{
    public const string LOOKUP_TAG = 'orillacart.order_lookup';
    /** The most orders one lookup gives: a search is for finding an order, not for listing many. */
    public const int MAX_FOUND = 500;
    /** Shorter text matches too much to be worth looking up. */
    public const int MIN_LENGTH = 3;

    /**
     * @param string $text what the shop typed, at least MIN_LENGTH characters
     *
     * @return list<int> ids of the orders whose text contains it, up to MAX_FOUND
     */
    public function ordersMatching(string $text): array;
}
