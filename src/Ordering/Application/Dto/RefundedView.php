<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * What a refund the order recorded gave back in tax, in all and by rate.
 */
final readonly class RefundedView
{
    /**
     * @param list<TaxLineView> $taxes
     */
    public function __construct(
        public string $tax,
        public array $taxes,
    ) {}
}
