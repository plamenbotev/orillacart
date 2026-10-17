<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application\Dto;

use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: replace the shop settings. Every field is required, so a request states the whole configuration;
 * the shop address fields take null for "not set".
 */
final readonly class UpdateSettings
{
    public function __construct(
        #[Assert\Regex('/^[A-Z]{3}$/', message: 'Use a three-letter currency code such as EUR.')]
        public string $currency,
        #[Assert\Range(min: 0, max: StoreSettings::MAX_PRICE_DECIMALS)]
        public int $priceDecimals,
        #[Assert\Choice(callback: [WeightUnit::class, 'values'])]
        public string $weightUnit,
        #[Assert\Choice(callback: [DimensionUnit::class, 'values'])]
        public string $dimensionUnit,
        #[Assert\Regex('/^[A-Z]{2}$/', message: 'Use a two-letter country code such as DE.')]
        public ?string $shopCountry,
        #[Assert\Regex('/^[A-Z0-9]{1,3}$/', message: 'Use the state code, such as CA.')]
        public ?string $shopState,
    ) {}
}
