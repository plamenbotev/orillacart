<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The next number of one series, such as the invoices of 2026. Read and moved on under a row lock, in the
 * transaction that issues the document: a rolled-back issue gives its number back, so none is ever skipped.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_counters')]
class InvoiceCounter
{
    #[ORM\Column]
    private int $next = 1;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 40)]
        private string $series,
    ) {}

    public function series(): string
    {
        return $this->series;
    }

    /** The number to use now; the counter moves on. */
    public function take(): int
    {
        return $this->next++;
    }
}
