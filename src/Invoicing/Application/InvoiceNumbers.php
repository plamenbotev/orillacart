<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

use OrillaCart\Invoicing\Domain\InvoiceRepository;
use OrillaCart\Ordering\Application\OrderLookup;

/**
 * Finds orders by an invoice's or a credit note's number, for the shop's order search.
 */
final readonly class InvoiceNumbers implements OrderLookup
{
    public function __construct(private InvoiceRepository $invoices) {}

    public function ordersMatching(string $text): array
    {
        return $this->invoices->orderIdsNumbered($text, self::MAX_FOUND);
    }
}
