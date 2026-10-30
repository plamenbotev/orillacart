<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application\Dto;

use OrillaCart\Settings\Domain\CurrencyPosition;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\ReportDay;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;

/**
 * The shop settings as admin screens and the REST API show them, with the choices each field allows.
 */
final readonly class SettingsView
{
    /**
     * @param list<string> $weightUnits
     * @param list<string> $dimensionUnits
     * @param list<string> $sellTo            countries the shop sells to; empty for everywhere
     * @param list<string> $currencyPositions
     */
    public function __construct(
        public string $currency,
        /** The decimals amounts are priced, charged and shown in. */
        public int $priceDecimals,
        public string $weightUnit,
        public string $dimensionUnit,
        public ?string $shopCountry,
        public ?string $shopState,
        public array $weightUnits,
        public array $dimensionUnits,
        public ?string $shopPostcode = null,
        public string $currencySign = '',
        public string $currencyPosition = 'after_space',
        public bool $catalogOnly = false,
        public bool $hidePrices = false,
        public array $sellTo = [],
        public array $currencyPositions = [],
        public bool $showLowestPrice = false,
        /** The shop's choice of fewer decimals than the currency has; null for the currency's own. */
        public ?int $fewerDecimals = null,
        /** The currency's own decimals (ISO 4217), the most amounts can have. */
        public int $currencyDecimals = 2,
        /** Deleting the plugin removes the shop's data too. */
        public bool $removeDataOnUninstall = false,
        public int $lowStockThreshold = StoreSettings::DEFAULT_LOW_STOCK_THRESHOLD,
        public ?int $unpaidHoldDays = null,
        public ?int $returnDays = null,
        public ?string $minimumOrder = null,
        /** The day the reports count an order on: paid or placed. */
        public string $reportDay = 'paid',
        /** @var list<string> */
        public array $reportDays = [],
    ) {}

    public static function fromSettings(StoreSettings $settings): self
    {
        return new self(
            $settings->currency->code,
            $settings->priceDecimals,
            $settings->weightUnit->value,
            $settings->dimensionUnit->value,
            $settings->shopCountry,
            $settings->shopState,
            WeightUnit::values(),
            DimensionUnit::values(),
            $settings->shopPostcode,
            $settings->currencySign,
            $settings->currencyPosition->value,
            $settings->catalogOnly,
            $settings->hidePrices,
            $settings->sellTo,
            CurrencyPosition::values(),
            $settings->showLowestPrice,
            $settings->fewerDecimals,
            $settings->currency->decimals(),
            $settings->removeDataOnUninstall,
            $settings->lowStockThreshold,
            $settings->unpaidHoldDays,
            $settings->returnDays,
            $settings->minimumOrder === null ? null : (string) $settings->minimumOrder,
            $settings->reportDay->value,
            ReportDay::values(),
        );
    }
}
