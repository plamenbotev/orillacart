<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * The refunds reported to tax services, by reference.
 */
interface TaxRefundReports
{
    public function reported(string $reference): bool;

    public function record(TaxRefundReport $report): void;
}
