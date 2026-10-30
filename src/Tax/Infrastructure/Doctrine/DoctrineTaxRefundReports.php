<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Tax\Domain\TaxRefundReport;
use OrillaCart\Tax\Domain\TaxRefundReports;

final readonly class DoctrineTaxRefundReports implements TaxRefundReports
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function reported(string $reference): bool
    {
        return $this->entityManager->find(TaxRefundReport::class, mb_substr($reference, 0, TaxRefundReport::MAX_REFERENCE_LENGTH)) !== null;
    }

    public function record(TaxRefundReport $report): void
    {
        $this->entityManager->persist($report);
        $this->entityManager->flush();
    }
}
