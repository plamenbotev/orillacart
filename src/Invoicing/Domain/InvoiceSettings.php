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
        /** Invoices and credit notes also as EN 16931 XML: NO_EINVOICES, UBL (Peppol BIS 3.0) or CII (XRechnung). */
        public string $eInvoices = self::NO_EINVOICES,
        /** Who the customer reaches about an invoice: XRechnung asks for both, Peppol for one. */
        public string $phone = '',
        public string $email = '',
        /** The seller's Peppol participant ID, scheme and identifier, such as 0208:0123456789; by default the VAT number's. */
        public string $peppolId = '',
    ) {
        if (!\in_array($eInvoices, self::EINVOICE_FORMATS, true)) {
            throw new RuleViolation('Choose the format of the e-invoices: UBL, CII or none.', 'orillacart_invalid_invoice_settings');
        }
        if ($phone !== '' && (preg_match(self::PHONE, $phone) !== 1 || preg_match_all('/\d/', $phone) < 3)) {
            throw new RuleViolation('Write the phone number with its digits, spaces, brackets, dots, dashes and a leading +.', 'orillacart_invalid_invoice_settings');
        }
        if ($email !== '' && (mb_strlen($email) > 254 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false)) {
            throw new RuleViolation('The invoice email is not an email address.', 'orillacart_invalid_invoice_settings');
        }
        if ($peppolId !== '' && preg_match(self::PEPPOL_ID, $peppolId) !== 1) {
            throw new RuleViolation('A Peppol ID is its four-digit scheme, a colon and the identifier, such as 0208:0123456789.', 'orillacart_invalid_invoice_settings');
        }
        if ($eInvoices === self::CII && $phone === '') {
            throw new RuleViolation('XRechnung needs a phone number for the seller.', 'orillacart_invalid_invoice_settings');
        }
        foreach (['legal name' => $legalName, 'address' => $address, 'VAT number' => $vatNumber, 'company number' => $companyNumber, 'bank details' => $bankDetails, 'footer' => $footer] as $what => $text) {
            if (mb_strlen($text) > self::MAX_TEXT) {
                throw new RuleViolation('The %1$s has up to %2$d characters.', 'orillacart_invalid_invoice_settings', values: [$what, self::MAX_TEXT]);
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

    public const string NO_EINVOICES = 'none';
    public const string UBL = 'ubl';
    public const string CII = 'cii';
    public const array EINVOICE_FORMATS = [self::NO_EINVOICES, self::UBL, self::CII];
    public const string PHONE = '/^\+?[0-9 ().\/-]{3,30}$/';
    public const string PEPPOL_ID = '/^\d{4}:[A-Za-z0-9._-]{1,50}$/';

    public function prefix(InvoiceKind $kind): string
    {
        return $kind === InvoiceKind::Invoice ? $this->invoicePrefix : $this->creditNotePrefix;
    }

    /** @return array<string, string> what an issued document keeps of the seller, as it was then */
    public function seller(): array
    {
        return ['legal_name' => $this->legalName, 'address' => $this->address, 'vat_number' => $this->vatNumber, 'company_number' => $this->companyNumber, 'bank_details' => $this->bankDetails, 'footer' => $this->footer, 'phone' => $this->phone, 'email' => $this->email, 'peppol_id' => $this->peppolId];
    }
}
