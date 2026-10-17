<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application\Dto;

use OrillaCart\Settings\Domain\DimensionUnit;
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
     */
    public function __construct(
        public string $currency,
        public int $priceDecimals,
        public string $weightUnit,
        public string $dimensionUnit,
        public array $weightUnits,
        public array $dimensionUnits,
    ) {}

    public static function fromSettings(StoreSettings $settings): self
    {
        return new self(
            $settings->currency->code,
            $settings->priceDecimals,
            $settings->weightUnit->value,
            $settings->dimensionUnit->value,
            WeightUnit::values(),
            DimensionUnit::values(),
        );
    }
}
