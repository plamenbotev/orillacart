<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

interface InvoiceLinks
{
    /** The printable page of a document, for whoever holds the order's key. */
    public function documentUrl(string $number, string $receipt): string;

    /** The document as an e-invoice (EN 16931 XML), for whoever holds the order's key. */
    public function xmlUrl(string $number, string $receipt): string;
}
