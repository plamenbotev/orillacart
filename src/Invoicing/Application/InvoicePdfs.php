<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

use OrillaCart\Invoicing\Application\Dto\InvoiceDocument;

/**
 * Prints an invoice or credit note to a PDF, as the invoice page lays it out.
 */
interface InvoicePdfs
{
    /**
     * @throws \RuntimeException when it cannot be printed
     */
    public function pdf(InvoiceDocument $document): string;
}
