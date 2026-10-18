<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application\Dto;

use OrillaCart\Invoicing\Domain\InvoiceSettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: who issues the shop's invoices, and how their numbers read.
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
    ) {}
}
