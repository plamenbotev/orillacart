<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress;

use OrillaCart\Settings\Domain\CurrencyPosition;
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
                self::optionalInt($stored, 'fewer_decimals'),
                WeightUnit::from(self::string($stored, 'weight_unit')),
                DimensionUnit::from(self::string($stored, 'dimension_unit')),
                self::optionalString($stored, 'shop_country'),
                self::optionalString($stored, 'shop_state'),
                self::optionalString($stored, 'shop_postcode'),
                self::optionalString($stored, 'currency_sign') ?? '',
                CurrencyPosition::tryFrom(self::optionalString($stored, 'currency_position') ?? '') ?? CurrencyPosition::AfterWithSpace,
                ($stored['catalog_only'] ?? false) === true,
                ($stored['hide_prices'] ?? false) === true,
                array_values(array_filter(\is_array($stored['sell_to'] ?? null) ? $stored['sell_to'] : [], \is_string(...))),
                ($stored['show_lowest_price'] ?? false) === true,
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
            'fewer_decimals' => $settings->fewerDecimals,
            'weight_unit' => $settings->weightUnit->value,
            'dimension_unit' => $settings->dimensionUnit->value,
            'shop_country' => $settings->shopCountry,
            'shop_state' => $settings->shopState,
            'shop_postcode' => $settings->shopPostcode,
            'currency_sign' => $settings->currencySign,
            'currency_position' => $settings->currencyPosition->value,
            'catalog_only' => $settings->catalogOnly,
            'hide_prices' => $settings->hidePrices,
            'sell_to' => $settings->sellTo,
            'show_lowest_price' => $settings->showLowestPrice,
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
    private static function optionalString(array $stored, string $key): ?string
    {
        $value = $stored[$key] ?? null;

        return $value === null || \is_string($value) ? $value : throw new \UnexpectedValueException(\sprintf('"%s" is not text.', $key));
    }

    /** @param array<array-key, mixed> $stored */
    private static function optionalInt(array $stored, string $key): ?int
    {
        $value = $stored[$key] ?? null;

        return $value === null || \is_int($value) ? $value : throw new \UnexpectedValueException(\sprintf('"%s" is not a number.', $key));
    }
}
