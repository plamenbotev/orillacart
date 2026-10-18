<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

use OrillaCart\Invoicing\Application\Dto\InvoiceDocument;
use OrillaCart\Invoicing\Application\Dto\InvoiceSettingsView;
use OrillaCart\Invoicing\Application\Dto\InvoiceView;
use OrillaCart\Invoicing\Application\Dto\UpdateInvoiceSettings;
use OrillaCart\Invoicing\Domain\Invoice;
use OrillaCart\Invoicing\Domain\InvoiceKind;
use OrillaCart\Invoicing\Domain\InvoiceNotFound;
use OrillaCart\Invoicing\Domain\InvoiceRepository;
use OrillaCart\Invoicing\Domain\InvoiceSettings;
use OrillaCart\Invoicing\Domain\InvoiceSettingsRepository;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Reports\Application\ShopCalendar;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * The shop's invoices: one for each order once it is paid, and a credit note for each refund. Each takes the
 * next number of its series in the transaction that pays or refunds the order, so the numbers have no gaps.
 */
final readonly class Invoices
{
    public function __construct(
        private InvoiceRepository $invoices,
        private InvoiceSettingsRepository $settings,
        private OrderService $orders,
        private InvoiceLinks $links,
        private ShopCalendar $calendar,
        private ClockInterface $clock,
    ) {}

    /**
     * The invoice of an order that was just paid; nothing when it has one. Run it in the payment's transaction.
     *
     * @throws OrderNotFound
     */
    public function invoice(int $orderId): void
    {
        foreach ($this->invoices->ofOrder($orderId) as $issued) {
            if ($issued->kind() === InvoiceKind::Invoice) {
                return;
            }
        }
        $order = $this->orders->order($orderId);
        $this->issue($orderId, InvoiceKind::Invoice, Money::of($order->total), $order->currency, []);
    }

    /**
     * The credit note of money given back. Run it in the refund's transaction.
     *
     * @param list<array{line: int, quantity: int}> $lines
     *
     * @throws OrderNotFound
     */
    public function creditNote(int $orderId, string $amount, array $lines): void
    {
        $order = $this->orders->order($orderId);
        $this->issue($orderId, InvoiceKind::CreditNote, Money::of($amount), $order->currency, $lines);
    }

    /**
     * @return list<InvoiceView>
     *
     * @throws OrderNotFound
     */
    public function ofOrder(int $orderId): array
    {
        $key = $this->orders->order($orderId)->key;

        return array_map(fn(Invoice $invoice): InvoiceView => $this->view($invoice, $key), $this->invoices->ofOrder($orderId));
    }

    /**
     * A printable document, for whoever holds its order's key.
     *
     * @throws InvoiceNotFound also for a key that is not its order's
     */
    public function document(string $number, string $orderKey): InvoiceDocument
    {
        $invoice = $this->invoices->findByNumber($number) ?? throw new InvoiceNotFound();
        try {
            $order = $this->orders->order($invoice->orderId());
        } catch (OrderNotFound) {
            throw new InvoiceNotFound();
        }
        if (!hash_equals($order->key, $orderKey)) {
            throw new InvoiceNotFound();
        }
        $corrects = null;
        if ($invoice->kind() === InvoiceKind::CreditNote) {
            foreach ($this->invoices->ofOrder($invoice->orderId()) as $issued) {
                if ($issued->kind() === InvoiceKind::Invoice) {
                    $corrects = $issued->number();
                }
            }
        }

        return new InvoiceDocument($this->view($invoice, $orderKey), $invoice->seller(), $order, $invoice->lines(), $corrects);
    }

    public function settings(): InvoiceSettingsView
    {
        return InvoiceSettingsView::fromSettings($this->settings->load());
    }

    public function updateSettings(UpdateInvoiceSettings $command): InvoiceSettingsView
    {
        $settings = new InvoiceSettings(
            trim($command->legalName),
            trim($command->address),
            trim($command->vatNumber),
            trim($command->companyNumber),
            trim($command->bankDetails),
            trim($command->footer),
            $command->invoicePrefix,
            $command->creditNotePrefix,
            $command->yearly,
        );
        $this->settings->save($settings);

        return InvoiceSettingsView::fromSettings($settings);
    }

    /**
     * @param list<array{line: int, quantity: int}> $lines
     */
    private function issue(int $orderId, InvoiceKind $kind, Money $amount, string $currency, array $lines): void
    {
        $settings = $this->settings->load();
        $now = $this->clock->now();
        $year = $now->setTimezone($this->calendar->timezone())->format('Y');
        $counter = $this->invoices->lockedCounter($kind->value . ($settings->yearly ? ':' . $year : ''));
        $sequence = $counter->take();
        $this->invoices->saveCounter($counter);
        $number = $settings->prefix($kind) . ($settings->yearly ? $year . '-' : '') . str_pad((string) $sequence, 5, '0', \STR_PAD_LEFT);
        $this->invoices->save(new Invoice($orderId, $kind, $number, $amount, $currency, $settings->seller(), $now, $lines));
    }

    private function view(Invoice $invoice, string $orderKey): InvoiceView
    {
        return new InvoiceView($invoice->number(), $invoice->kind()->value, (string) $invoice->amount(), $invoice->currency(), $invoice->issuedAt()->format(\DATE_ATOM), $this->links->documentUrl($invoice->number(), $orderKey));
    }
}
