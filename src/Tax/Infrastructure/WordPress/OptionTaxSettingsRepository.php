<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\WordPress;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tax\Domain\TaxSettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * Keeps the tax settings in one autoloaded WordPress option.
 */
final readonly class OptionTaxSettingsRepository implements TaxSettingsRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_tax_settings';

    public function __construct(private LoggerInterface $logger) {}

    public function load(): TaxSettings
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return TaxSettings::defaults();
        }

        $basis = TaxBasis::tryFrom(\is_string($stored['basis'] ?? null) ? $stored['basis'] : '');
        $group = $stored['default_group_id'] ?? null;
        if ($basis === null || ($group !== null && !\is_int($group))) {
            $this->logger->warning('Stored tax settings are not valid, using the defaults.');

            return TaxSettings::defaults();
        }

        $regions = $stored['origin_regions'] ?? [];
        $regions = \is_array($regions) ? array_values(array_filter($regions, static fn(mixed $region): bool => \is_string($region) && preg_match(TaxSettings::REGION, $region) === 1)) : [];

        return new TaxSettings($basis, $group, ($stored['prices_include_tax'] ?? false) === true, ($stored['reverse_charge'] ?? false) === true, ($stored['rounds_each_line'] ?? false) === true, $regions);
    }

    public function save(TaxSettings $settings): void
    {
        update_option(self::OPTION, ['basis' => $settings->basis->value, 'default_group_id' => $settings->defaultGroupId, 'prices_include_tax' => $settings->pricesIncludeTax, 'reverse_charge' => $settings->reverseCharge, 'rounds_each_line' => $settings->roundsEachLine, 'origin_regions' => $settings->originRegions], true);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
