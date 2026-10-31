<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * When an order placed now would arrive, from now (or the estimate before it) until a moment.
 */
final readonly class DeliveryEstimateView
{
    /**
     * @param string                  $from    "2026-11-06", the earliest day, in the shop's calendar
     * @param string                  $to      the latest day; $from for one day
     * @param \DateTimeImmutable      $until   when it stops holding
     * @param \DateTimeImmutable|null $orderBy the carrier's cutoff to order by for it ($until); null when only the
     *                                         days moving on end it
     */
    public function __construct(
        public string $from,
        public string $to,
        public \DateTimeImmutable $until,
        public ?\DateTimeImmutable $orderBy = null,
    ) {}
}
