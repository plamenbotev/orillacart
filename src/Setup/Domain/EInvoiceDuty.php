<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Domain;

/**
 * A country's duty to issue invoices to businesses in a set electronic form, from a day on: what the setup tells
 * a shop selling to businesses there, and whether the shop's own e-invoices meet it.
 */
final readonly class EInvoiceDuty
{
    public function __construct(
        /** The day issuing becomes required for every business, Y-m-d; larger ones may start earlier. */
        public string $from,
        /** How, in English: "Peppol, in EN 16931 UBL". */
        public string $how,
        /** Whether the shop's UBL or CII e-invoices meet it, sent through Peppol by an e-invoice sender add-on. */
        public bool $met,
    ) {}
}
