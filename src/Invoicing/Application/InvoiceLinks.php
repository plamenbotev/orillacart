<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

interface InvoiceLinks
{
    /** The printable page of a document, for whoever holds its key (InvoiceKeys). */
    public function documentUrl(string $number, string $key): string;

    /** The document as an e-invoice (EN 16931 XML), for whoever holds its key (InvoiceKeys). */
    public function xmlUrl(string $number, string $key): string;
}
