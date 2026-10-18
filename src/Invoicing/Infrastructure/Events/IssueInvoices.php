<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Infrastructure\Events;

use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Ordering\Domain\Event\OrderPaid;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An order that is paid gets its invoice, and money given back its credit note, in the same transaction.
 */
final readonly class IssueInvoices
{
    public function __construct(private Invoices $invoices) {}

    #[AsEventListener]
    public function paid(OrderPaid $event): void
    {
        $this->invoices->invoice($event->orderId);
    }

    #[AsEventListener]
    public function refunded(OrderRefunded $event): void
    {
        $this->invoices->creditNote($event->orderId, $event->amount, array_map(static fn(int $line, int $quantity): array => ['line' => $line, 'quantity' => $quantity], array_keys($event->quantities), array_values($event->quantities)), $event->taxes);
    }
}
