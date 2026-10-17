<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * The shop settings were saved with different values. Announced as the action orillacart_settings_changed.
 */
final readonly class SettingsChanged implements PublicEvent
{
    public function __construct(
        public StoreSettings $previous,
        public StoreSettings $current,
    ) {}

    public function hookName(): string
    {
        return 'settings_changed';
    }

    /**
     * @return list<array{currency: string, price_decimals: int, weight_unit: string, dimension_unit: string}> current, then previous values
     */
    public function hookArguments(): array
    {
        return [self::values($this->current), self::values($this->previous)];
    }

    /**
     * @return array{currency: string, price_decimals: int, weight_unit: string, dimension_unit: string}
     */
    private static function values(StoreSettings $settings): array
    {
        return [
            'currency' => $settings->currency->code,
            'price_decimals' => $settings->priceDecimals,
            'weight_unit' => $settings->weightUnit->value,
            'dimension_unit' => $settings->dimensionUnit->value,
        ];
    }
}
