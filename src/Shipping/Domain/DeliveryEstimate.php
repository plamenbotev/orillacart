<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

/**
 * When an order placed now would arrive: on a day, or between two, in the shop's calendar. It holds until a
 * moment: the carrier's cutoff, after which an order counts from the next day, or midnight, when the days move on.
 */
final readonly class DeliveryEstimate
{
    /**
     * @param string             $from    "2026-11-06", the earliest day
     * @param string             $to      the latest day; $from for one day
     * @param \DateTimeImmutable $until   when it stops holding
     * @param bool               $cutoff  $until is the carrier's cutoff: ordering before it is what gets it there
     */
    public function __construct(
        public string $from,
        public string $to,
        public \DateTimeImmutable $until,
        public bool $cutoff = false,
    ) {}

    /** Whether it arrives sooner than another: the earlier first day, then the earlier last. */
    public function sooner(self $other): bool
    {
        return [$this->from, $this->to] < [$other->from, $other->to];
    }
}
