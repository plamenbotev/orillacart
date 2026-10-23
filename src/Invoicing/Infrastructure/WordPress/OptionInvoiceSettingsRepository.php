<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Infrastructure\WordPress;

use OrillaCart\Invoicing\Domain\InvoiceSettings;
use OrillaCart\Invoicing\Domain\InvoiceSettingsRepository;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use Psr\Log\LoggerInterface;

/**
 * Keeps the invoice settings in one WordPress option.
 */
final readonly class OptionInvoiceSettingsRepository implements InvoiceSettingsRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_invoice_settings';

    public function __construct(private LoggerInterface $logger) {}

    public function load(): InvoiceSettings
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return new InvoiceSettings();
        }
        $text = static fn(string $key, string $default = ''): string => \is_string($stored[$key] ?? null) ? $stored[$key] : $default;

        try {
            return new InvoiceSettings(
                $text('legal_name'),
                $text('address'),
                $text('vat_number'),
                $text('company_number'),
                $text('bank_details'),
                $text('footer'),
                $text('invoice_prefix', 'INV-'),
                $text('credit_note_prefix', 'CN-'),
                ($stored['yearly'] ?? true) === true,
                ($stored['attach_to_emails'] ?? true) === true,
                $text('e_invoices', InvoiceSettings::NO_EINVOICES),
            );
        } catch (RuleViolation) {
            $this->logger->warning('Stored invoice settings are not valid, using the defaults.');

            return new InvoiceSettings();
        }
    }

    public function save(InvoiceSettings $settings): void
    {
        update_option(self::OPTION, [
            'legal_name' => $settings->legalName,
            'address' => $settings->address,
            'vat_number' => $settings->vatNumber,
            'company_number' => $settings->companyNumber,
            'bank_details' => $settings->bankDetails,
            'footer' => $settings->footer,
            'invoice_prefix' => $settings->invoicePrefix,
            'credit_note_prefix' => $settings->creditNotePrefix,
            'yearly' => $settings->yearly,
            'attach_to_emails' => $settings->attachToEmails,
            'e_invoices' => $settings->eInvoices,
        ], false);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
