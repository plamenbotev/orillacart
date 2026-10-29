<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Invoicing\Application\Dto\InvoiceFile;
use OrillaCart\Invoicing\Application\InvoiceAttachments;
use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Invoicing\Domain\InvoiceKind;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Application\Dto\EmailAttachment;
use OrillaCart\Notifications\Application\Dto\SendOrderEmail;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailRules;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Shipping\Application\ShipmentTracking;
use OrillaCart\Storefront\Application\LegalSettingsService;
use Psr\Log\LoggerInterface;

/**
 * Sends the emails about orders, as mail.order jobs in the mail lane: a slow or failing mail server never slows
 * or stops the order, and a mail it refused is tried again (SendOrderEmails). Sending again from the order's
 * screen is immediate, so the shop sees whether it went.
 *
 * A template renders the body and has a "subject" block; the shop writes its own on the Emails screen, and a
 * theme overrides the file by placing one of the same name in its orillacart/emails/ folder.
 */
final readonly class OrderEmails
{
    public function __construct(
        private OrderService $orders,
        private OrderLinks $links,
        private EmailSettingsRepository $settings,
        private EmailWriter $writer,
        private Mailer $mailer,
        private LoggerInterface $logger,
        private ShipmentTracking $shipments,
        private Jobs $jobs,
        private Invoices $invoices,
        private ReceiptLinks $receipts,
        private InvoiceAttachments $attachments,
        private LegalSettingsService $legal,
    ) {}

    public function statusChanged(int $orderId, string $previous, string $status, bool $tellCustomer = true, bool $tellShop = true): void
    {
        foreach (EmailRules::forStatusChange($previous, $status) as $kind) {
            if ($kind->isForShop() ? $tellShop : $tellCustomer) {
                $this->queue($orderId, $kind);
            }
        }
        if ($tellCustomer && $previous === 'pending_payment' && \in_array($status, ['on_hold', 'processing'], true) && self::owesShown($this->orders->order($orderId))) {
            $this->queue($orderId, EmailKind::OrderBackordered);
        }
    }

    /** The customer can follow a shipment a courier booked. */
    public function trackingAdded(int $orderId): void
    {
        $this->queue($orderId, EmailKind::TrackingAdded);
    }

    /** The order waits at the shop's place the customer collects it from. */
    public function readyForPickup(int $orderId): void
    {
        $this->queue($orderId, EmailKind::ReadyForPickup);
    }

    /** The note goes by its id: the job keeps no text a customer or the shop wrote. */
    public function noteAdded(int $orderId, int $noteId): void
    {
        $this->queue($orderId, EmailKind::CustomerNote, $noteId);
    }

    /**
     * A new link to the receipt an expired one was for, to the order's email. A link the shop never made, or
     * one of an order deleted since, sends nothing, and whoever asked hears the same.
     */
    public function receiptLink(string $expired): void
    {
        $read = $this->receipts->read($expired);
        if ($read === null) {
            return;
        }
        try {
            $this->orders->order($read[0]);
        } catch (OrderNotFound) {
            return;
        }
        $this->queue($read[0], EmailKind::ReceiptLink);
    }

    /**
     * A guest looks up their order by its number and email: the link to its receipt goes to the order's email,
     * when they match. Whoever asked hears the same either way, and sees nothing of the order.
     */
    public function findOrder(int $number, string $email): void
    {
        try {
            $order = $this->orders->order($number);
        } catch (OrderNotFound) {
            return;
        }
        if (mb_strtolower(trim($order->email)) === mb_strtolower(trim($email))) {
            $this->queue($order->number, EmailKind::ReceiptLink);
        }
    }

    /** The customer is reminded that the order waits for their payment. */
    public function paymentReminder(int $orderId): void
    {
        $this->queue($orderId, EmailKind::PaymentReminder);
    }

    /** The shop hears why an order needs attention: every reason waiting when the email goes, in one. */
    public function needsAttention(int $orderId): void
    {
        $this->queue($orderId, EmailKind::OrderAttention);
    }

    /**
     * Money went back: the customer hears how much, and for which items, unless the shop said not to; the shop
     * hears it too, as a refund may come from the provider's own dashboard.
     *
     * @param array<int, int> $quantities items refunded by line position
     */
    public function refunded(int $orderId, string $amount, bool $full, array $quantities, bool $tellCustomer = true, ?string $credit = null): void
    {
        $refund = ['amount' => $amount, 'full' => $full, 'quantities' => array_map(static fn(int $line, int $quantity): array => ['line' => $line, 'quantity' => $quantity], array_keys($quantities), array_values($quantities)), 'credit' => $credit];
        if ($tellCustomer) {
            $this->queue($orderId, EmailKind::OrderRefunded, refund: $refund);
        }
        $this->queue($orderId, EmailKind::RefundMade, refund: $refund);
    }

    /**
     * Who an order's email goes to: the customer, or the shop's own address for the shop's emails.
     *
     * @throws OrderNotFound
     */
    public function recipient(int $orderId, EmailKind $kind): string
    {
        return $kind->isForShop() ? $this->writer->shopAddress() : $this->orders->order($orderId)->email;
    }

    /**
     * Sends an order's email again; the shop asks from the order's screen.
     *
     * @throws OrderNotFound
     *
     * @return bool whether the mail system accepted it
     */
    public function resend(int $orderId, SendOrderEmail $command): bool
    {
        $this->orders->order($orderId);

        try {
            $this->send($orderId, EmailKind::from($command->kind));
        } catch (EmailNotWritten) {
            return false;
        } catch (EmailNotSent $e) {
            $this->logger->warning($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Writes and sends an email now; SendOrderEmails runs it for each job. A customer note's email is not sent
     * once the note is gone, an attention email once the shop handled the order, nor a payment reminder once
     * the order is paid or no longer on hold.
     *
     * @throws OrderNotFound
     * @throws EmailNotWritten its template failed: trying again would fail the same way
     * @throws EmailNotSent    the mail system refused it, perhaps for a while
     *
     * @param int|null                                                                               $noteId for the customer note email: the note it brings
     * @param array{amount: string, full: bool, quantities: list<array{line: int, quantity: int}>, credit?: string|null}|null $refund for the refund email: the refund it tells of
     */
    public function send(int $orderId, EmailKind $kind, ?int $noteId = null, ?array $refund = null): void
    {
        $order = $this->orders->order($orderId);
        if ($order->anonymised && !$kind->isForShop()) {
            // Erased since it was queued: there is no one to write to any more.
            return;
        }
        $note = match ($kind) {
            EmailKind::CustomerNote => self::noteText($order, $noteId),
            EmailKind::OrderAttention => $order->attention,
            default => '',
        };
        if ($note === null) {
            // The note is gone, as with an order made anonymous, or the shop handled what needed attention.
            return;
        }
        if ($kind === EmailKind::PaymentReminder && ($order->status !== 'on_hold' || $order->paidAt !== null)) {
            // Paid or called off since it was queued.
            return;
        }
        try {
            $email = $this->write($order, $kind, $note, $refund);
        } catch (EmailNotWritten $e) {
            $this->logger->error('The {kind} email of order {order} could not be written: {message}', ['kind' => $kind->value, 'order' => $orderId, 'message' => $e->getMessage(), 'exception' => $e]);

            throw $e;
        }

        if (!$this->mailer->send($email)) {
            // Without the address: the message is kept with the job, which outlives the order's personal data.
            throw new EmailNotSent(\sprintf('The %s email of order %d to %s was not sent: the mail system refused it.', $kind->value, $orderId, $kind->isForShop() ? 'the shop' : 'the customer'));
        }
    }

    /**
     * An order's email as it would go, for the shop to look at while editing its template: a note, what needed
     * attention and a refund are made up when the order has none.
     *
     * @throws OrderNotFound
     * @throws EmailNotWritten
     */
    public function preview(int $orderId, EmailKind $kind): Email
    {
        $order = $this->orders->order($orderId);
        $note = match ($kind) {
            EmailKind::CustomerNote => 'A note from the shop to the customer.',
            EmailKind::OrderAttention => $order->attention ?? 'What the order needs attention for.',
            default => '',
        };
        $refund = \in_array($kind, [EmailKind::OrderRefunded, EmailKind::RefundMade], true)
            ? ['amount' => $order->total, 'full' => true, 'quantities' => array_map(static fn(int $line, OrderLineView $view): array => ['line' => $line, 'quantity' => $view->quantity], array_keys($order->lines), array_values($order->lines))]
            : null;

        return $this->write($order, $kind, $note, $refund);
    }

    /**
     * @param array{amount: string, full: bool, quantities: list<array{line: int, quantity: int}>, credit?: string|null}|null $refund
     *
     * @throws EmailNotWritten
     */
    private function write(OrderView $order, EmailKind $kind, string $note, ?array $refund): Email
    {
        // The customer reads the words they ordered in; the shop its own (design 51).
        if (!$kind->isForShop()) {
            $order = $this->orders->inCustomerWords($order->number);
        }
        $extra = ['note' => $note, 'refund' => null];
        if ($refund !== null) {
            $lines = [];
            foreach ($refund['quantities'] as $refunded) {
                $line = $order->lines[$refunded['line']] ?? null;
                if ($line !== null) {
                    $lines[] = ['name' => $line->name, 'quantity' => $refunded['quantity']];
                }
            }
            $extra['refund'] = ['amount' => $refund['amount'], 'full' => $refund['full'], 'lines' => $lines, 'credit' => \is_string($refund['credit'] ?? null) ? $refund['credit'] : null];
        }
        // Built in the reader's language: the receipt's page and the legal texts are theirs.
        $context = fn(): array => [
            'order' => $order,
            'receipt_url' => $this->links->receiptUrl($order->number),
            'for_shop' => $kind->isForShop(),
            'shipments' => $this->shipments->forOrder($order->number, !$kind->isForShop()),
            'invoices' => $kind->isForShop() ? [] : $this->invoices->ofOrder($order->number),
            // The confirmation carries the terms and the right of withdrawal, as a durable medium must.
            'legal_texts' => \in_array($kind, [EmailKind::OrderReceived, EmailKind::OrderProcessing], true) ? $this->legal->confirmationTexts() : [],
        ] + $extra;

        // The customer reads it in the language they ordered in; the shop in the site's.
        if ($kind->isForShop()) {
            return $this->writer->write($kind->template(), $kind->value, $this->writer->shopAddress(), null, $context, $order->email, $order->number);
        }
        $email = $this->writer->write($kind->template(), $kind->value, $order->email, $order->locale, $context, null, $order->number);
        $files = match ($kind) {
            EmailKind::OrderProcessing, EmailKind::OrderCompleted => $this->attachments->files($order->number, InvoiceKind::Invoice, $order->locale),
            EmailKind::OrderRefunded => $this->attachments->files($order->number, InvoiceKind::CreditNote, $order->locale),
            default => [],
        };

        return $files === [] ? $email : $email->with(array_map(static fn(InvoiceFile $file): EmailAttachment => new EmailAttachment($file->filename, $file->content), $files));
    }

    /**
     * One job per order and kind while it waits; customer notes and refunds each get their own. A job keeps
     * ids and amounts, never a note's text or an address.
     *
     * @param array<string, mixed>|null $refund
     */
    private function queue(int $orderId, EmailKind $kind, ?int $noteId = null, ?array $refund = null): void
    {
        if (!$this->settings->load()->sends($kind)) {
            return;
        }
        $this->jobs->push(
            SendOrderEmails::TYPE,
            ['order' => $orderId, 'kind' => $kind->value] + ($noteId === null ? [] : ['note' => $noteId]) + ($refund === null ? [] : ['refund' => $refund]),
            $kind === EmailKind::CustomerNote || $refund !== null ? null : "{$orderId}:{$kind->value}",
        );
    }

    /** Whether the order owes items the customer is told of. */
    private static function owesShown(OrderView $order): bool
    {
        foreach ($order->lines as $line) {
            if ($line->backordered > 0 && $line->backorderShown) {
                return true;
            }
        }

        return false;
    }

    private static function noteText(OrderView $order, ?int $noteId): ?string
    {
        foreach ($order->notes as $note) {
            if ($note->id === $noteId && $note->forCustomer) {
                return $note->text;
            }
        }

        return null;
    }
}
