<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Infrastructure\WordPress;

use OrillaCart\Invoicing\Application\InvoiceLinks;
use OrillaCart\Invoicing\Infrastructure\WordPress\Hooks\ShowInvoice;

/**
 * Invoices are pages of their own under the home address, found by number and a key of their own (InvoiceKeys).
 */
final readonly class WordPressInvoiceLinks implements InvoiceLinks
{
    public function documentUrl(string $number, string $key): string
    {
        return add_query_arg([ShowInvoice::QUERY => rawurlencode($number), ShowInvoice::KEY => rawurlencode($key)], home_url('/'));
    }

    public function xmlUrl(string $number, string $key): string
    {
        return add_query_arg([ShowInvoice::QUERY => rawurlencode($number), ShowInvoice::KEY => rawurlencode($key), ShowInvoice::FORMAT => 'xml'], home_url('/'));
    }
}
