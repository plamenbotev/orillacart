<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Invoicing;

use OrillaCart\Invoicing\Domain\Invoice;
use OrillaCart\Invoicing\Domain\InvoiceCounter;
use OrillaCart\Invoicing\Domain\InvoiceRepository;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;

final class InMemoryInvoices implements InvoiceRepository
{
    /** @var array<int, Invoice> */
    public array $invoices = [];
    /** @var array<string, InvoiceCounter> */
    public array $counters = [];
    private int $nextId = 1;

    public function __construct(private readonly ?ImmediateTransactions $transactions = null) {}

    public function findByNumber(string $number): ?Invoice
    {
        foreach ($this->invoices as $invoice) {
            if ($invoice->number() === $number) {
                return $invoice;
            }
        }

        return null;
    }

    public function ofOrder(int $orderId): array
    {
        return array_values(array_filter($this->invoices, static fn(Invoice $invoice): bool => $invoice->orderId() === $orderId));
    }

    public function lockedCounter(string $series): InvoiceCounter
    {
        $this->transactions?->requireOpen();

        return $this->counters[$series] ??= new InvoiceCounter($series);
    }

    public function save(Invoice $invoice): void
    {
        $id = new \ReflectionProperty(Invoice::class, 'id');
        if ($id->getValue($invoice) === null) {
            $id->setValue($invoice, $this->nextId++);
        }
        $this->invoices[$invoice->id()] = $invoice;
    }

    public function saveCounter(InvoiceCounter $counter): void
    {
        $this->counters[$counter->series()] = $counter;
    }
}
