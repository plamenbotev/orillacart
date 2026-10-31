<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

/**
 * How long a carrier takes to deliver, in working days (Monday to Friday) from the day of the order: "2–4 days".
 * The product page tells the shopper by when it arrives, for a carrier without a delivery schedule.
 */
final readonly class DeliveryWindow
{
    public const int MAX_DAYS = 60;

    /**
     * @param int $minDays 0 delivers the same day at the earliest
     * @param int $maxDays at least $minDays
     *
     * @throws ShippingRuleViolation
     */
    public function __construct(
        public int $minDays,
        public int $maxDays,
    ) {
        if ($minDays < 0 || $maxDays < $minDays || $maxDays > self::MAX_DAYS) {
            throw new ShippingRuleViolation('A delivery time is 0 to %d working days, the longest no shorter than the shortest.', 'orillacart_invalid_delivery_window', values: [self::MAX_DAYS]);
        }
    }

    /**
     * When an order placed now arrives; it holds until midnight.
     *
     * @param \DateTimeImmutable $now in the shop's time zone
     */
    public function estimate(\DateTimeImmutable $now): DeliveryEstimate
    {
        $today = $now->setTime(0, 0);

        return new DeliveryEstimate(self::workingDaysAfter($today, $this->minDays), self::workingDaysAfter($today, $this->maxDays), $today->modify('+1 day'));
    }

    /** The day that many working days after the day; 0 is the day itself on a working day, else the next one. */
    private static function workingDaysAfter(\DateTimeImmutable $day, int $days): string
    {
        while ((int) $day->format('N') > 5) {
            $day = $day->modify('+1 day');
        }
        while ($days > 0) {
            $day = $day->modify('+1 day');
            if ((int) $day->format('N') <= 5) {
                --$days;
            }
        }

        return $day->format('Y-m-d');
    }
}
