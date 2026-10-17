<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress;

use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use Psr\Log\LoggerInterface;

/**
 * Keeps the settings in one autoloaded WordPress option, read on nearly every request.
 */
final readonly class OptionSettingsRepository implements SettingsRepository, LifecycleListener
{
    public const string OPTION = 'orillacart_settings';

    public function __construct(private LoggerInterface $logger) {}

    public function load(): StoreSettings
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return StoreSettings::defaults();
        }

        try {
            return new StoreSettings(
                new Currency(self::string($stored, 'currency')),
                self::int($stored, 'price_decimals'),
                WeightUnit::from(self::string($stored, 'weight_unit')),
                DimensionUnit::from(self::string($stored, 'dimension_unit')),
            );
        } catch (RuleViolation|\ValueError|\UnexpectedValueException $e) {
            $this->logger->warning('Stored shop settings are not valid, using the defaults: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return StoreSettings::defaults();
        }
    }

    public function save(StoreSettings $settings): void
    {
        update_option(self::OPTION, [
            'currency' => $settings->currency->code,
            'price_decimals' => $settings->priceDecimals,
            'weight_unit' => $settings->weightUnit->value,
            'dimension_unit' => $settings->dimensionUnit->value,
        ], true);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }

    /** @param array<array-key, mixed> $stored */
    private static function string(array $stored, string $key): string
    {
        $value = $stored[$key] ?? null;

        return \is_string($value) ? $value : throw new \UnexpectedValueException(\sprintf('"%s" is missing or not text.', $key));
    }

    /** @param array<array-key, mixed> $stored */
    private static function int(array $stored, string $key): int
    {
        $value = $stored[$key] ?? null;

        return \is_int($value) ? $value : throw new \UnexpectedValueException(\sprintf('"%s" is missing or not a number.', $key));
    }
}
