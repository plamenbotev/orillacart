<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application\Dto;

/**
 * What every report says of itself: its days, the days it is compared with, its currency, and whether it is
 * up to date.
 */
final readonly class ReportFrame
{
    /**
     * @param list<string> $otherCurrencies currencies of paid orders the report leaves out: it counts the shop's own
     * @param int          $pending         orders changed but not counted yet: the report catches up soon
     */
    public function __construct(
        public string $from,
        public string $to,
        public string $interval,
        public ?string $previousFrom,
        public ?string $previousTo,
        public string $currency,
        public array $otherCurrencies,
        public int $pending,
    ) {}
}
