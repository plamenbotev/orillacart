<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Tax;

use OrillaCart\Tax\Domain\TaxRefundReport;
use OrillaCart\Tax\Domain\TaxRefundReports;

final class InMemoryTaxRefundReports implements TaxRefundReports
{
    /** @var array<string, TaxRefundReport> by reference */
    public array $reports = [];

    public function reported(string $reference): bool
    {
        return isset($this->reports[$reference]);
    }

    public function record(TaxRefundReport $report): void
    {
        $this->reports[$report->reference()] = $report;
    }
}
