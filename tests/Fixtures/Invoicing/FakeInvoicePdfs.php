<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Invoicing;

use OrillaCart\Invoicing\Application\Dto\InvoiceDocument;
use OrillaCart\Invoicing\Application\InvoicePdfs;

/** Prints a stand-in PDF naming the document, or fails when told to. */
final class FakeInvoicePdfs implements InvoicePdfs
{
    public bool $failing = false;

    public function pdf(InvoiceDocument $document): string
    {
        if ($this->failing) {
            throw new \RuntimeException('The printer is out of paper.');
        }

        return "%PDF-1.7 {$document->invoice->number} for #{$document->order->number}";
    }
}
