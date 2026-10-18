<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application\Dto;

/**
 * The best products, categories or coupons of a period, each with its figure in the compared period.
 */
final readonly class RankingReport
{
    /**
     * @param list<array<string, int|string|null>> $rows
     */
    public function __construct(
        public ReportFrame $frame,
        public array $rows,
    ) {}
}
