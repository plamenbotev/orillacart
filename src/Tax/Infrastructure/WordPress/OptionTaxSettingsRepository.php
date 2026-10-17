<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\WordPress;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tax\Domain\TaxSettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * Keeps the tax settings in one autoloaded WordPress option.
 */
final readonly class OptionTaxSettingsRepository implements TaxSettingsRepository, LifecycleListener
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

        return new TaxSettings($basis, $group);
    }

    public function save(TaxSettings $settings): void
    {
        update_option(self::OPTION, ['basis' => $settings->basis->value, 'default_group_id' => $settings->defaultGroupId], true);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
