<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Infrastructure\WordPress;

use OrillaCart\Invoicing\Application\InvoiceLinks;
use OrillaCart\Invoicing\Infrastructure\WordPress\Hooks\ShowInvoice;

/**
 * Invoices are pages of their own under the home address, found by number and their order's key.
 */
final readonly class WordPressInvoiceLinks implements InvoiceLinks
{
    public function documentUrl(string $number, string $receipt): string
    {
        return add_query_arg([ShowInvoice::QUERY => rawurlencode($number), ShowInvoice::KEY => rawurlencode($receipt)], home_url('/'));
    }

    public function xmlUrl(string $number, string $receipt): string
    {
        return add_query_arg([ShowInvoice::QUERY => rawurlencode($number), ShowInvoice::KEY => rawurlencode($receipt), ShowInvoice::FORMAT => 'xml'], home_url('/'));
    }
}
