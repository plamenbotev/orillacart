<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application\Dto;

use OrillaCart\Invoicing\Domain\InvoiceSettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: who issues the shop's invoices, how their numbers read, and whether the customer's emails carry them.
 */
final readonly class UpdateInvoiceSettings
{
    public function __construct(
        #[Assert\Length(max: InvoiceSettings::MAX_TEXT)]
        public string $legalName = '',
        #[Assert\Length(max: InvoiceSettings::MAX_TEXT)]
        public string $address = '',
        #[Assert\Length(max: InvoiceSettings::MAX_TEXT)]
        public string $vatNumber = '',
        #[Assert\Length(max: InvoiceSettings::MAX_TEXT)]
        public string $companyNumber = '',
        #[Assert\Length(max: InvoiceSettings::MAX_TEXT)]
        public string $bankDetails = '',
        #[Assert\Length(max: InvoiceSettings::MAX_TEXT)]
        public string $footer = '',
        #[Assert\Regex(pattern: '/^[A-Za-z0-9\/._-]{0,20}$/', message: 'Use up to 20 letters, digits, dots, dashes, slashes or underscores.')]
        public string $invoicePrefix = 'INV-',
        #[Assert\Regex(pattern: '/^[A-Za-z0-9\/._-]{0,20}$/', message: 'Use up to 20 letters, digits, dots, dashes, slashes or underscores.')]
        public string $creditNotePrefix = 'CN-',
        public bool $yearly = true,
        public bool $attachToEmails = true,
        /** Also as EN 16931 XML: none, ubl (Peppol BIS 3.0) or cii (XRechnung). */
        #[Assert\Choice(choices: InvoiceSettings::EINVOICE_FORMATS)]
        public string $eInvoices = InvoiceSettings::NO_EINVOICES,
        #[Assert\Regex(pattern: InvoiceSettings::PHONE, message: 'Write the phone number with its digits, spaces, brackets, dots, dashes and a leading +.')]
        public string $phone = '',
        #[Assert\Length(max: 254)]
        #[Assert\Email]
        public string $email = '',
        #[Assert\Regex(pattern: InvoiceSettings::PEPPOL_ID, message: 'A Peppol ID is its four-digit scheme, a colon and the identifier, such as 0208:0123456789.')]
        public string $peppolId = '',
    ) {}
}
