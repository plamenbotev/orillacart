<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * Who issues the shop's invoices, as the law asks them to say, and how their numbers read: a prefix, the year
 * when numbering restarts every year, then the next number of the series, such as INV-2026-00042.
 */
final readonly class InvoiceSettings
{
    public const int MAX_TEXT = 2000;

    public function __construct(
        public string $legalName = '',
        public string $address = '',
        public string $vatNumber = '',
        public string $companyNumber = '',
        public string $bankDetails = '',
        public string $footer = '',
        public string $invoicePrefix = 'INV-',
        public string $creditNotePrefix = 'CN-',
        public bool $yearly = true,
        /** The customer's emails carry the invoice, or the credit note, as a PDF. */
        public bool $attachToEmails = true,
    ) {
        foreach (['legal name' => $legalName, 'address' => $address, 'VAT number' => $vatNumber, 'company number' => $companyNumber, 'bank details' => $bankDetails, 'footer' => $footer] as $what => $text) {
            if (mb_strlen($text) > self::MAX_TEXT) {
                throw new RuleViolation(\sprintf('The %s has up to %d characters.', $what, self::MAX_TEXT), 'orillacart_invalid_invoice_settings');
            }
        }
        foreach ([$invoicePrefix, $creditNotePrefix] as $prefix) {
            if (preg_match('/^[A-Za-z0-9\/._-]{0,20}$/', $prefix) !== 1) {
                throw new RuleViolation('A number prefix has up to 20 letters, digits, dots, dashes, slashes or underscores.', 'orillacart_invalid_invoice_settings');
            }
        }
        if ($invoicePrefix === $creditNotePrefix) {
            throw new RuleViolation('Invoices and credit notes need different prefixes, so their numbers never meet.', 'orillacart_invalid_invoice_settings');
        }
    }

    public function prefix(InvoiceKind $kind): string
    {
        return $kind === InvoiceKind::Invoice ? $this->invoicePrefix : $this->creditNotePrefix;
    }

    /** @return array<string, string> what an issued document keeps of the seller, as it was then */
    public function seller(): array
    {
        return ['legal_name' => $this->legalName, 'address' => $this->address, 'vat_number' => $this->vatNumber, 'company_number' => $this->companyNumber, 'bank_details' => $this->bankDetails, 'footer' => $this->footer];
    }
}
