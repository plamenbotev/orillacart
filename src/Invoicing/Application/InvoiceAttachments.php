<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

use OrillaCart\Invoicing\Application\Dto\InvoiceFile;
use OrillaCart\Invoicing\Domain\InvoiceKind;
use OrillaCart\Invoicing\Domain\InvoiceSettingsRepository;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Shared\Lifecycle\Languages;
use Psr\Log\LoggerInterface;

/**
 * The PDFs the customer's emails carry, unless the shop said not to: the order's invoice, or its latest credit
 * note, in the customer's language. One that cannot be printed is logged, and the email goes without it.
 */
final readonly class InvoiceAttachments
{
    public function __construct(
        private Invoices $invoices,
        private InvoiceSettingsRepository $settings,
        private InvoicePdfs $pdfs,
        private Languages $languages,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param string|null $locale the customer's language; null for the site's
     */
    public function file(int $orderId, InvoiceKind $kind, ?string $locale): ?InvoiceFile
    {
        if (!$this->settings->load()->attachToEmails) {
            return null;
        }
        try {
            $document = $this->invoices->latest($orderId, $kind);
            if ($document === null) {
                return null;
            }

            return new InvoiceFile($document->invoice->number . '.pdf', $this->languages->in($locale, fn(): string => $this->pdfs->pdf($document)));
        } catch (OrderNotFound) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('The {kind} of order {order} could not be printed to attach: {message}', ['kind' => $kind->value, 'order' => $orderId, 'message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }
    }
}
