<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Settings;

use OrillaCart\Customers\Domain\CustomerSettingsRepository;
use OrillaCart\Customers\Infrastructure\WordPress\OptionCustomerSettingsRepository;
use OrillaCart\Exchange\Domain\ExportChoices;
use OrillaCart\Exchange\Domain\ExportKind;
use OrillaCart\Exchange\Infrastructure\WordPress\OptionExportChoices;
use OrillaCart\Invoicing\Domain\InvoiceSettingsRepository;
use OrillaCart\Invoicing\Infrastructure\WordPress\OptionInvoiceSettingsRepository;
use OrillaCart\Newsletter\Domain\NewsletterSettingsRepository;
use OrillaCart\Newsletter\Infrastructure\WordPress\OptionNewsletterSettingsRepository;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Notifications\Infrastructure\WordPress\OptionEmailSettingsRepository;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Files\WebServerFileDelivery;
use OrillaCart\Reviews\Domain\ReviewSettingsRepository;
use OrillaCart\Reviews\Infrastructure\WordPress\OptionReviewSettingsRepository;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Infrastructure\WordPress\OptionEngineSettingsRepository;
use OrillaCart\Settings\Application\SettingsArchive;
use OrillaCart\Settings\Domain\CurrencySettingsRepository;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Settings\Infrastructure\WordPress\OptionCurrencySettingsRepository;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;
use OrillaCart\Shared\Files\FileDelivery;
use OrillaCart\Storefront\Domain\StorefrontSettingsRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionStorefrontSettingsRepository;
use OrillaCart\Tax\Domain\ImportRegistrationsRepository;
use OrillaCart\Tax\Domain\NexusThresholdsRepository;
use OrillaCart\Tax\Domain\TaxSettingsRepository;
use OrillaCart\Tax\Infrastructure\WordPress\OptionImportRegistrationsRepository;
use OrillaCart\Tax\Infrastructure\WordPress\OptionNexusThresholdsRepository;
use OrillaCart\Tax\Infrastructure\WordPress\OptionTaxSettingsRepository;

/**
 * The settings each screen saves, as their options hold them: the shop's, tax, emails, invoices, customers, the
 * newsletter, reviews, the storefront, export columns, downloads and the search engine. The shop's pages and
 * download folder are this site's own and stay out; so do secrets (the search engine's password), which an
 * import leaves as this site has them. Payment methods, carriers and tax rates are the shop's records, made on
 * their screens; they are not settings here.
 */
final readonly class OptionSettingsArchive implements SettingsArchive
{
    public const string FORMAT = 'orillacart-settings';
    /** Keys whose values a file never carries. */
    private const string SECRET = '/(secret|password|token|api_?key)/i';

    public function __construct(
        private PluginContext $context,
        private SettingsRepository $shop,
        private TaxSettingsRepository $tax,
        private EmailSettingsRepository $emails,
        private InvoiceSettingsRepository $invoices,
        private CustomerSettingsRepository $customers,
        private NewsletterSettingsRepository $newsletter,
        private ReviewSettingsRepository $reviews,
        private StorefrontSettingsRepository $storefront,
        private CurrencySettingsRepository $currencies,
        private ExportChoices $exportColumns,
        private FileDelivery $downloads,
        private EngineSettingsRepository $search,
        private NexusThresholdsRepository $nexus,
        private ImportRegistrationsRepository $importSchemes,
    ) {}

    public function export(): array
    {
        $settings = [];
        foreach ($this->sections() as $section => [$option]) {
            $value = get_option($option);
            if (\is_array($value)) {
                $settings[$section] = self::withoutSecrets($value);
            }
        }

        return [
            'format' => self::FORMAT,
            'version' => $this->context->version,
            'exported_at' => gmdate(DATE_ATOM),
            'site' => home_url(),
            'settings' => $settings,
        ];
    }

    public function import(array $file): array
    {
        if (($file['format'] ?? null) !== self::FORMAT || !\is_array($file['settings'] ?? null)) {
            return ['imported' => [], 'refused' => ['file' => 'This is not a file of the shop\'s settings.']];
        }
        $imported = [];
        $refused = [];
        $sections = $this->sections();
        foreach ($file['settings'] as $section => $value) {
            if (!\is_string($section) || !isset($sections[$section])) {
                $refused[(string) $section] = 'The shop has no such settings.';
                continue;
            }
            if (!\is_array($value)) {
                $refused[$section] = 'The settings are not a list.';
                continue;
            }
            [$option, $roundTrip] = $sections[$section];
            $before = get_option($option);
            update_option($option, self::keepingSecrets($value, \is_array($before) ? $before : []), true);
            try {
                $roundTrip();
                $changed = self::changed($value, get_option($option));
                $problem = $changed === [] ? null : 'These are not values the shop can use: ' . implode(', ', $changed) . '.';
            } catch (\Throwable $e) {
                $problem = $e->getMessage();
            }
            if ($problem === null) {
                $imported[] = $section;
            } else {
                $before === false ? delete_option($option) : update_option($option, $before, true);
                $refused[$section] = $problem;
            }
        }

        return ['imported' => $imported, 'refused' => $refused];
    }

    /**
     * Each section's option, and how the shop reads it and saves it back: a valid file comes back as it was, so
     * what comes back otherwise was not one the shop can use.
     *
     * @return array<string, array{string, \Closure(): mixed}>
     */
    private function sections(): array
    {
        return [
            'shop' => [OptionSettingsRepository::OPTION, fn() => $this->shop->save($this->shop->load())],
            'tax' => [OptionTaxSettingsRepository::OPTION, fn() => $this->tax->save($this->tax->load())],
            'nexus' => [OptionNexusThresholdsRepository::OPTION, fn() => $this->nexus->save($this->nexus->load())],
            'import_schemes' => [OptionImportRegistrationsRepository::OPTION, fn() => $this->importSchemes->save($this->importSchemes->load())],
            'emails' => [OptionEmailSettingsRepository::OPTION, fn() => $this->emails->save($this->emails->load())],
            'invoices' => [OptionInvoiceSettingsRepository::OPTION, fn() => $this->invoices->save($this->invoices->load())],
            'customers' => [OptionCustomerSettingsRepository::OPTION, fn() => $this->customers->save($this->customers->load())],
            'newsletter' => [OptionNewsletterSettingsRepository::OPTION, fn() => $this->newsletter->save($this->newsletter->load())],
            'reviews' => [OptionReviewSettingsRepository::OPTION, fn() => $this->reviews->save($this->reviews->load())],
            'storefront' => [OptionStorefrontSettingsRepository::OPTION, fn() => $this->storefront->save($this->storefront->load())],
            'currencies' => [OptionCurrencySettingsRepository::OPTION, fn() => $this->currencies->save($this->currencies->load())],
            'export_columns' => [OptionExportChoices::OPTION, function (): void {
                foreach (ExportKind::cases() as $kind) {
                    $columns = $this->exportColumns->chosen($kind);
                    if ($columns !== null) {
                        $this->exportColumns->choose($kind, $columns);
                    }
                }
            }],
            'downloads' => [WebServerFileDelivery::OPTION, fn() => $this->downloads->change($this->downloads->settings())],
            'search' => [OptionEngineSettingsRepository::OPTION, fn() => $this->search->save($this->search->load())],
        ];
    }

    /**
     * The keys whose values the shop kept otherwise than written; keys it does not keep at all (left from an
     * older version) are passed over.
     *
     * @param array<array-key, mixed> $written
     *
     * @return list<string>
     */
    private static function changed(array $written, mixed $kept): array
    {
        $kept = \is_array($kept) ? self::withoutSecrets($kept) : [];
        $changed = [];
        foreach (self::withoutSecrets($written) as $key => $value) {
            if (\array_key_exists($key, $kept) && $kept[$key] !== $value) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function withoutSecrets(array $value): array
    {
        foreach ($value as $key => $item) {
            if (preg_match(self::SECRET, (string) $key) === 1) {
                unset($value[$key]);
            } elseif (\is_array($item)) {
                $value[$key] = self::withoutSecrets($item);
            }
        }

        return $value;
    }

    /**
     * The file's settings, with this site's secrets where they were.
     *
     * @param array<array-key, mixed> $value
     * @param array<array-key, mixed> $current
     *
     * @return array<array-key, mixed>
     */
    private static function keepingSecrets(array $value, array $current): array
    {
        $value = self::withoutSecrets($value);
        foreach ($current as $key => $item) {
            if (preg_match(self::SECRET, (string) $key) === 1) {
                $value[$key] = $item;
            } elseif (\is_array($item) && \is_array($value[$key] ?? null)) {
                $value[$key] = self::keepingSecrets($value[$key], $item);
            }
        }

        return $value;
    }
}
