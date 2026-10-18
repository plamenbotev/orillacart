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
     * @return list<array{currency: string, price_decimals: int, weight_unit: string, dimension_unit: string, shop_country: ?string, shop_state: ?string, shop_postcode: ?string, currency_sign: string, currency_position: string, catalog_only: bool, hide_prices: bool, sell_to: list<string>}> current, then previous values
     */
    public function hookArguments(): array
    {
        return [self::values($this->current), self::values($this->previous)];
    }

    /**
     * @return array{currency: string, price_decimals: int, weight_unit: string, dimension_unit: string, shop_country: ?string, shop_state: ?string, shop_postcode: ?string, currency_sign: string, currency_position: string, catalog_only: bool, hide_prices: bool, sell_to: list<string>}
     */
    private static function values(StoreSettings $settings): array
    {
        return [
            'currency' => $settings->currency->code,
            'price_decimals' => $settings->priceDecimals,
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
        ];
    }
}
