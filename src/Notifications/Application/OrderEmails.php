<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Application\Dto\SendOrderEmail;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailRules;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shipping\Application\ShipmentTracking;
use Psr\Log\LoggerInterface;

/**
 * Sends the emails about orders, as mail.order jobs in the mail lane: a slow or failing mail server never slows
 * or stops the order, and a mail it refused is tried again (SendOrderEmails). Sending again from the order's
 * screen is immediate, so the shop sees whether it went.
 *
 * A template renders the body and has a "subject" block; a theme overrides it by placing a file of the same
 * name in its orillacart/emails/ folder.
 */
final readonly class OrderEmails
{
    public function __construct(
        private OrderService $orders,
        private OrderLinks $links,
        private EmailSettingsRepository $settings,
        private TemplateRenderer $templates,
        private Mailer $mailer,
        private Site $site,
        private LoggerInterface $logger,
        private ShipmentTracking $shipments,
        private Jobs $jobs,
        private Invoices $invoices,
        private Languages $languages,
    ) {}

    public function statusChanged(int $orderId, string $previous, string $status, bool $tellCustomer = true, bool $tellShop = true): void
    {
        foreach (EmailRules::forStatusChange($previous, $status) as $kind) {
            if ($kind->isForShop() ? $tellShop : $tellCustomer) {
                $this->queue($orderId, $kind);
            }
        }
    }

    public function noteAdded(int $orderId, string $text): void
    {
        $this->queue($orderId, EmailKind::CustomerNote, $text);
    }

    /** The shop hears why an order needs attention. */
    public function needsAttention(int $orderId, string $reason): void
    {
        $this->queue($orderId, EmailKind::OrderAttention, $reason);
    }

    /**
     * Money went back: the customer hears how much, and for which items.
     *
     * @param array<int, int> $quantities items refunded by line position
     */
    public function refunded(int $orderId, string $amount, bool $full, array $quantities): void
    {
        $refund = ['amount' => $amount, 'full' => $full, 'quantities' => array_map(static fn(int $line, int $quantity): array => ['line' => $line, 'quantity' => $quantity], array_keys($quantities), array_values($quantities))];
        $this->queue($orderId, EmailKind::OrderRefunded, refund: $refund);
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
     * Writes and sends an email now; SendOrderEmails runs it for each job.
     *
     * @throws OrderNotFound
     * @throws EmailNotWritten its template failed: trying again would fail the same way
     * @throws EmailNotSent    the mail system refused it, perhaps for a while
     *
     * @param array{amount: string, full: bool, quantities: list<array{line: int, quantity: int}>}|null $refund for the refund email: the refund it tells of
     */
    public function send(int $orderId, EmailKind $kind, string $note = '', ?array $refund = null): void
    {
        $settings = $this->settings->load();
        $order = $this->orders->order($orderId);
        $extra = ['note' => $note, 'refund' => null];
        if ($refund !== null) {
            $lines = [];
            foreach ($refund['quantities'] as $refunded) {
                $line = $order->lines[$refunded['line']] ?? null;
                if ($line !== null) {
                    $lines[] = ['name' => $line->name, 'quantity' => $refunded['quantity']];
                }
            }
            $extra['refund'] = ['amount' => $refund['amount'], 'full' => $refund['full'], 'lines' => $lines];
        }
        $context = [
            'order' => $order,
            'receipt_url' => $this->links->receiptUrl($order->key),
            'site_name' => $this->site->name(),
            'site_url' => $this->site->url(),
            'for_shop' => $kind->isForShop(),
            'shipments' => $this->shipments->forOrder($orderId),
            'invoices' => $kind->isForShop() ? [] : $this->invoices->ofOrder($orderId),
        ] + $extra;

        try {
            // The customer reads it in the language they ordered in; the shop in the site's.
            $locale = $kind->isForShop() ? null : $order->locale;
            $context['lang'] = str_replace('_', '-', $locale ?? $this->languages->site());
            [$subject, $body] = $this->languages->in($locale, fn(): array => [
                // A subject is plain text: what the template escaped for HTML reads as written.
                html_entity_decode(trim($this->templates->renderBlock($kind->template(), 'subject', $context)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                $this->templates->render($kind->template(), $context),
            ]);
            $email = new Email(
                $kind->isForShop() ? ($settings->shopAddress ?? $this->site->adminEmail()) : $order->email,
                $subject,
                $body,
                PlainText::fromHtml($body),
                $settings->fromName ?? $this->site->name(),
                $settings->fromAddress ?? $this->site->adminEmail(),
                $kind->isForShop() ? $order->email : null,
                $kind->value,
                $order->number,
            );
        } catch (\Throwable $e) {
            $this->logger->error('The {kind} email of order {order} could not be written: {message}', ['kind' => $kind->value, 'order' => $orderId, 'message' => $e->getMessage(), 'exception' => $e]);

            throw new EmailNotWritten(\sprintf('The %s email could not be written: %s', $kind->value, $e->getMessage()), previous: $e);
        }

        if (!$this->mailer->send($email)) {
            throw new EmailNotSent(\sprintf('The %s email of order %d to %s was not sent: the mail system refused it.', $kind->value, $orderId, $email->to));
        }
    }

    /**
     * One job per order and kind while it waits; customer notes and refunds each get their own.
     *
     * @param array<string, mixed>|null $refund
     */
    private function queue(int $orderId, EmailKind $kind, string $note = '', ?array $refund = null): void
    {
        if (!$this->settings->load()->sends($kind)) {
            return;
        }
        $this->jobs->push(
            SendOrderEmails::TYPE,
            ['order' => $orderId, 'kind' => $kind->value] + ($note === '' ? [] : ['note' => $note]) + ($refund === null ? [] : ['refund' => $refund]),
            $kind === EmailKind::CustomerNote || $kind === EmailKind::OrderAttention || $refund !== null ? null : "{$orderId}:{$kind->value}",
        );
    }
}
