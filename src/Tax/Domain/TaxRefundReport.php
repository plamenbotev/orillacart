<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A refund the tax service was told of, by the reference the refund got when it was made: a job run again (its
 * worker died after the service answered, before the job was settled) finds it here and tells nobody twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tax_refund_reports')]
class TaxRefundReport
{
    public const int MAX_REFERENCE_LENGTH = 100;

    #[ORM\Id]
    #[ORM\Column(length: self::MAX_REFERENCE_LENGTH)]
    private string $reference;

    #[ORM\Column]
    private int $orderId;

    #[ORM\Column]
    private \DateTimeImmutable $reportedAt;

    public function __construct(string $reference, int $orderId, \DateTimeImmutable $reportedAt)
    {
        $this->reference = mb_substr($reference, 0, self::MAX_REFERENCE_LENGTH);
        $this->orderId = $orderId;
        $this->reportedAt = $reportedAt;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function reportedAt(): \DateTimeImmutable
    {
        return $this->reportedAt;
    }
}
