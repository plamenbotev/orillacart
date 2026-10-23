<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application\Dto;

use OrillaCart\Invoicing\Domain\InvoiceSettings;

final readonly class InvoiceSettingsView
{
    public function __construct(
        public string $legalName,
        public string $address,
        public string $vatNumber,
        public string $companyNumber,
        public string $bankDetails,
        public string $footer,
        public string $invoicePrefix,
        public string $creditNotePrefix,
        public bool $yearly,
        public bool $attachToEmails = true,
        public string $eInvoices = 'none',
        public string $phone = '',
        public string $email = '',
        public string $peppolId = '',
    ) {}

    public static function fromSettings(InvoiceSettings $settings): self
    {
        return new self($settings->legalName, $settings->address, $settings->vatNumber, $settings->companyNumber, $settings->bankDetails, $settings->footer, $settings->invoicePrefix, $settings->creditNotePrefix, $settings->yearly, $settings->attachToEmails, $settings->eInvoices, $settings->phone, $settings->email, $settings->peppolId);
    }
}
