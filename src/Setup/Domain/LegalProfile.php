<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Domain;

/**
 * What the law of a country asks of a shop selling online to consumers there, as far as the setup can set it up:
 * the right to withdraw, a page naming the seller, a box to accept the terms, and e-invoices to businesses.
 */
final readonly class LegalProfile
{
    public function __construct(
        public string $country,
        /** Days a consumer may withdraw from a distance purchase without a reason; null where the law gives none. */
        public ?int $withdrawalDays = null,
        /** Whether the seller must be named, with address and registration, on a page of its own. */
        public bool $legalNotice = false,
        /** What that page is called where the law names it ("Impressum"). */
        public string $legalNoticeTitle = 'Legal notice',
        /** Whether checkout asks the customer to accept the terms of sale, and the button says that ordering means paying. */
        public bool $consumerCheckout = false,
        public ?EInvoiceDuty $eInvoices = null,
    ) {}
}
