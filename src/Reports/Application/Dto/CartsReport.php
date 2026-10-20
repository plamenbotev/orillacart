<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application\Dto;

use OrillaCart\Cart\Application\Dto\AbandonedCartView;

/**
 * Carts left at checkout in a period: how many and worth what, how many were reminded and how many ordered after
 * all, and the latest of them.
 */
final readonly class CartsReport
{
    /**
     * @param array{abandoned: int, value: string, reminded: int, recovered: int, recovered_value: string, recovered_after_reminder: int}      $totals
     * @param array{abandoned: int, value: string, reminded: int, recovered: int, recovered_value: string, recovered_after_reminder: int}|null $previous
     * @param list<AbandonedCartView>                                                                                                           $latest
     */
    public function __construct(
        public ReportFrame $frame,
        public array $totals,
        public ?array $previous,
        public array $latest,
    ) {}
}
