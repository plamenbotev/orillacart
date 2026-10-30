<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Infrastructure\Events;

use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Invoicing\Application\SendEInvoices;
use OrillaCart\Ordering\Domain\Event\OrderPaid;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An order that is paid gets its invoice, and money given back its credit note, in the same transaction; each
 * then goes to the add-ons that send e-invoices on.
 */
final readonly class IssueInvoices
{
    public function __construct(
        private Invoices $invoices,
        private SendEInvoices $send,
    ) {}

    #[AsEventListener]
    public function paid(OrderPaid $event): void
    {
        $number = $this->invoices->invoice($event->orderId);
        if ($number !== null) {
            $this->send->forDocument($number);
        }
    }

    /** Before the refund's emails are queued: each names the credit note it carries. */
    #[AsEventListener(priority: 10)]
    public function refunded(OrderRefunded $event): void
    {
        $this->send->forDocument($this->invoices->creditNote($event->orderId, $event->amount, array_map(static fn(int $line, int $quantity): array => ['line' => $line, 'quantity' => $quantity], array_keys($event->quantities), array_values($event->quantities)), $event->taxes));
    }
}
