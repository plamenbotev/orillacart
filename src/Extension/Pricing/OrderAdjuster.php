<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Pricing;

/**
 * Changes the prices of an order as checkout prices it, after the coupons: a loyalty discount, a B2B price
 * agreement, a surcharge. Each adjustment is for one line or the whole order (spread over its lines), and the
 * order keeps it with this adjuster's id, its label and its reason. Tax is on what is left.
 */
interface OrderAdjuster
{
    /** Lowercase letters, digits and underscores, up to 40; unique among order adjusters. */
    public function id(): string;

    /**
     * @return list<Adjustment> none to leave the prices as they are
     */
    public function adjust(AdjustmentQuery $query): array;
}
