<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

use OrillaCart\Invoicing\Application\Dto\InvoiceBuyer;
use OrillaCart\Invoicing\Application\Dto\InvoiceDocument;
use OrillaCart\Invoicing\Application\Dto\InvoiceSettingsView;
use OrillaCart\Invoicing\Application\Dto\InvoiceView;
use OrillaCart\Invoicing\Application\Dto\Seller;
use OrillaCart\Invoicing\Application\Dto\UpdateInvoiceSettings;
use OrillaCart\Invoicing\Domain\Invoice;
use OrillaCart\Invoicing\Domain\InvoiceKind;
use OrillaCart\Invoicing\Domain\InvoiceNotFound;
use OrillaCart\Invoicing\Domain\InvoiceRepository;
use OrillaCart\Invoicing\Domain\InvoiceSettings;
use OrillaCart\Invoicing\Domain\InvoiceSettingsRepository;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\Dto\TaxLineView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Reports\Application\ShopCalendar;
use OrillaCart\Shared\Domain\Currency;
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
        private ReceiptLinks $receipts,
    ) {}

    /**
     * The invoice of an order that was just paid, by its number; nothing when it has one. Run it in the payment's
     * transaction.
     *
     * @throws OrderNotFound
     */
    public function invoice(int $orderId): ?string
    {
        foreach ($this->invoices->ofOrder($orderId) as $issued) {
            if ($issued->kind() === InvoiceKind::Invoice) {
                return null;
            }
        }
        $order = $this->orders->order($orderId);

        return $this->issue($orderId, InvoiceKind::Invoice, Money::of($order->total), $order->currency, []);
    }

    /**
     * The credit note of money given back. Run it in the refund's transaction.
     *
     * @param list<array{line: int, quantity: int}>                       $lines
     * @param list<array{name: string, percent: string, amount: string}> $taxes the tax inside the amount, by rate
     *
     * @throws OrderNotFound
     */
    public function creditNote(int $orderId, string $amount, array $lines, array $taxes = []): string
    {
        $order = $this->orders->order($orderId);

        return $this->issue($orderId, InvoiceKind::CreditNote, Money::of($amount), $order->currency, $lines, $taxes);
    }

    /**
     * @return list<InvoiceView>
     *
     * @throws OrderNotFound
     */
    public function ofOrder(int $orderId): array
    {
        $this->orders->order($orderId);
        $receipt = $this->receipts->issue($orderId);

        return array_map(fn(Invoice $invoice): InvoiceView => $this->view($invoice, $receipt), $this->invoices->ofOrder($orderId));
    }

    /**
     * A printable document, for whoever holds a link to its order's receipt that still works.
     *
     * @throws InvoiceNotFound also for a receipt link that is not its order's, or expired
     */
    public function document(string $number, string $receipt): InvoiceDocument
    {
        $invoice = $this->invoices->findByNumber($number) ?? throw new InvoiceNotFound();
        $read = $this->receipts->read($receipt);
        if ($read === null || $read[1] || $read[0] !== $invoice->orderId()) {
            throw new InvoiceNotFound();
        }

        return $this->documentOf($invoice, $receipt);
    }

    /**
     * A document by its number, for the shop's own work (sending it on); its link carries a new key.
     *
     * @throws InvoiceNotFound
     */
    public function issued(string $number): InvoiceDocument
    {
        $invoice = $this->invoices->findByNumber($number) ?? throw new InvoiceNotFound();
        try {
            $receipt = $this->receipts->issue($invoice->orderId());
        } catch (OrderNotFound) {
            throw new InvoiceNotFound();
        }

        return $this->documentOf($invoice, $receipt);
    }

    /**
     * @throws InvoiceNotFound
     */
    private function documentOf(Invoice $invoice, string $receipt): InvoiceDocument
    {
        try {
            $order = $this->orders->order($invoice->orderId());
        } catch (OrderNotFound) {
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

        return new InvoiceDocument(
            $this->view($invoice, $receipt),
            $invoice->seller(),
            $order,
            $invoice->lines(),
            $corrects,
            $invoice->taxes(),
            self::inShopCurrency($order, $invoice->kind() === InvoiceKind::CreditNote ? $invoice->taxes() : array_map(static fn(TaxLineView $tax): array => ['name' => $tax->name, 'percent' => $tax->percent, 'amount' => $tax->amount], $order->taxes)),
            // Billed to whom it was issued to: an erasure or a changed contact since leaves it as it was.
            InvoiceBuyer::fromStored($invoice->buyer()) ?? InvoiceBuyer::of($order),
        );
    }

    /**
     * A document's taxes in the shop's currency, at the order's exchange rate; null for one in the shop's currency.
     *
     * @param list<array{name: string, percent: string, amount: string}> $taxes
     *
     * @return array{currency: string, rate: string, taxes: list<array{name: string, percent: string, amount: string}>, total: string}|null
     */
    private static function inShopCurrency(OrderView $order, array $taxes): ?array
    {
        if ($order->shopCurrency === null || $order->exchangeRate === null) {
            return null;
        }
        $decimals = new Currency($order->shopCurrency)->decimals();
        $converted = array_map(static fn(array $tax): array => ['name' => $tax['name'], 'percent' => $tax['percent'], 'amount' => (string) Money::of($tax['amount'])->from($order->exchangeRate)->rounded($decimals)], $taxes);

        return [
            'currency' => $order->shopCurrency,
            'rate' => $order->exchangeRate,
            'taxes' => $converted,
            'total' => (string) array_reduce($converted, static fn(Money $sum, array $tax): Money => $sum->add(Money::of($tax['amount'])), Money::zero()),
        ];
    }

    /**
     * The document of the order's invoice, or of its latest credit note, for the shop's own emails; null when it has
     * none.
     */
    public function latest(int $orderId, InvoiceKind $kind): ?InvoiceDocument
    {
        $latest = null;
        foreach ($this->invoices->ofOrder($orderId) as $issued) {
            if ($issued->kind() === $kind) {
                $latest = $issued;
            }
        }
        if ($latest === null) {
            return null;
        }

        return $this->document($latest->number(), $this->receipts->issue($orderId));
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
            $command->attachToEmails,
            $command->eInvoices,
            trim($command->phone),
            trim($command->email),
            trim($command->peppolId),
        );
        $this->settings->save($settings);

        return InvoiceSettingsView::fromSettings($settings);
    }

    /** Adds a line to the footer of invoices, such as why no VAT is charged, unless the footer says it already. */
    public function addFooterNote(string $note): InvoiceSettingsView
    {
        $current = $this->settings();
        if (str_contains($current->footer, trim($note))) {
            return $current;
        }

        return $this->updateSettings(new UpdateInvoiceSettings(
            $current->legalName,
            $current->address,
            $current->vatNumber,
            $current->companyNumber,
            $current->bankDetails,
            trim($current->footer . "\n" . trim($note)),
            $current->invoicePrefix,
            $current->creditNotePrefix,
            $current->yearly,
            $current->attachToEmails,
            $current->eInvoices,
            $current->phone,
            $current->email,
            $current->peppolId,
        ));
    }

    /** The seller invoices name, the other invoice settings as they are. */
    public function changeSeller(Seller $seller): InvoiceSettingsView
    {
        $current = $this->settings();

        return $this->updateSettings(new UpdateInvoiceSettings(
            $seller->legalName,
            $seller->address,
            $seller->vatNumber,
            $seller->companyNumber,
            $current->bankDetails,
            $current->footer,
            $current->invoicePrefix,
            $current->creditNotePrefix,
            $current->yearly,
            $current->attachToEmails,
            $current->eInvoices,
            $seller->phone,
            $seller->email,
            $current->peppolId,
        ));
    }

    /**
     * @param list<array{line: int, quantity: int}>                       $lines
     * @param list<array{name: string, percent: string, amount: string}> $taxes
     */
    private function issue(int $orderId, InvoiceKind $kind, Money $amount, string $currency, array $lines, array $taxes = []): string
    {
        $settings = $this->settings->load();
        $now = $this->clock->now();
        $year = $now->setTimezone($this->calendar->timezone())->format('Y');
        $counter = $this->invoices->lockedCounter($kind->value . ($settings->yearly ? ':' . $year : ''));
        $sequence = $counter->take();
        $this->invoices->saveCounter($counter);
        $number = $settings->prefix($kind) . ($settings->yearly ? $year . '-' : '') . str_pad((string) $sequence, 5, '0', \STR_PAD_LEFT);
        $buyer = InvoiceBuyer::of($this->orders->order($orderId))->stored();
        $this->invoices->save(new Invoice($orderId, $kind, $number, $amount, $currency, $settings->seller(), $now, $lines, $taxes, $buyer));

        return $number;
    }

    private function view(Invoice $invoice, string $receipt): InvoiceView
    {
        return new InvoiceView(
            $invoice->number(),
            $invoice->kind()->value,
            (string) $invoice->amount(),
            $invoice->currency(),
            $invoice->issuedAt()->format(\DATE_ATOM),
            $this->links->documentUrl($invoice->number(), $receipt),
            $this->settings->load()->eInvoices === InvoiceSettings::NO_EINVOICES ? null : $this->links->xmlUrl($invoice->number(), $receipt),
        );
    }
}
