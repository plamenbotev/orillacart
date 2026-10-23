<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Invoice;

/**
 * An invoice or credit note as an e-invoice, with what a sender needs to find its recipient.
 */
final readonly class EInvoiceFile
{
    public const string INVOICE = 'invoice';
    public const string CREDIT_NOTE = 'credit_note';
    public const string UBL = 'ubl';
    public const string CII = 'cii';

    public function __construct(
        /** INV-2026-00042 */
        public string $number,
        /** INVOICE or CREDIT_NOTE */
        public string $kind,
        public int $orderNumber,
        /** UBL (Peppol BIS Billing 3.0) or CII (XRechnung) */
        public string $format,
        public string $filename,
        public string $xml,
        public string $buyerEmail,
        /** ISO 3166-1 alpha-2 of the billing address. */
        public string $buyerCountry,
        /** The business customer's VAT or tax number, as checked; null for a consumer. */
        public ?string $buyerTaxNumber,
        /** The customer's own reference for the order, or null. */
        public ?string $buyerReference,
        /**
         * The buyer's Peppol participant ID the file names, scheme and identifier (9930:DE123456789), when it is
         * for Peppol: UBL, with a Peppol ID for both the shop and the buyer. Null for anything else, a consumer's
         * invoice among them, which goes by email.
         */
        public ?string $buyerPeppolId = null,
    ) {}
}
