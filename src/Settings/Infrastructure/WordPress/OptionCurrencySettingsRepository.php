<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress;

use OrillaCart\Settings\Domain\CurrencySettings;
use OrillaCart\Settings\Domain\CurrencySettingsRepository;
use OrillaCart\Settings\Domain\OfferedCurrency;
use OrillaCart\Settings\Domain\PriceRounding;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Shared\WordPress\AutoloadedOption;

final readonly class OptionCurrencySettingsRepository implements CurrencySettingsRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_currencies';

    public function load(): CurrencySettings
    {
        $stored = get_option(self::OPTION);
        $offered = [];
        foreach (\is_array($stored) && \is_array($stored['offered'] ?? null) ? $stored['offered'] : [] as $row) {
            if (!\is_array($row) || !\is_string($row['code'] ?? null) || !\is_string($row['rate'] ?? null)) {
                continue;
            }
            try {
                $offered[] = new OfferedCurrency(new Currency($row['code']), $row['rate'], PriceRounding::tryFrom(\is_string($row['rounding'] ?? null) ? $row['rounding'] : '') ?? PriceRounding::Decimals);
            } catch (RuleViolation) {
                continue;
            }
        }
        try {
            return new CurrencySettings($offered);
        } catch (RuleViolation) {
            return new CurrencySettings();
        }
    }

    public function save(CurrencySettings $settings): void
    {
        update_option(self::OPTION, ['offered' => array_map(static fn(OfferedCurrency $offered): array => ['code' => $offered->currency->code, 'rate' => $offered->rate, 'rounding' => $offered->rounding->value], $settings->offered)], true);
    }

    public function activate(): void
    {
        $this->upgrade(null);
    }

    /** Read on every storefront request: stored with its defaults, autoloaded, so reading it costs no query. */
    public function upgrade(?string $fromVersion): void
    {
        AutoloadedOption::keep(self::OPTION, fn() => $this->save($this->load()));
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
