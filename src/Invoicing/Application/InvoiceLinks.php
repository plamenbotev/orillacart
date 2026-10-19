<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

interface InvoiceLinks
{
    /** The printable page of a document, for whoever holds the order's key. */
    public function documentUrl(string $number, string $receipt): string;
}
