<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application\Dto;

use OrillaCart\Settings\Domain\CurrencyPosition;
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
    /**
     * @param list<string> $sellTo countries the shop sells to; empty for everywhere
     */
    public function __construct(
        #[Assert\Regex('/^[A-Z]{3}$/', message: 'Use a three-letter currency code such as EUR.')]
        public string $currency,
        /** Fewer decimals than the currency has; null for the currency's own. */
        #[Assert\PositiveOrZero]
        public ?int $fewerDecimals,
        #[Assert\Choice(callback: [WeightUnit::class, 'values'])]
        public string $weightUnit,
        #[Assert\Choice(callback: [DimensionUnit::class, 'values'])]
        public string $dimensionUnit,
        #[Assert\Regex('/^[A-Z]{2}$/', message: 'Use a two-letter country code such as DE.')]
        public ?string $shopCountry,
        #[Assert\Regex('/^[A-Z0-9]{1,3}$/', message: 'Use the state code, such as CA.')]
        public ?string $shopState,
        #[Assert\Length(min: 1, max: 20)]
        public ?string $shopPostcode = null,
        #[Assert\Length(max: StoreSettings::MAX_SIGN_LENGTH)]
        public string $currencySign = '',
        #[Assert\Choice(callback: [CurrencyPosition::class, 'values'])]
        public string $currencyPosition = 'after_space',
        public bool $catalogOnly = false,
        public bool $hidePrices = false,
        #[Assert\All([new Assert\Regex('/^[A-Z]{2}$/', message: 'Use two-letter country codes such as DE.')])]
        public array $sellTo = [],
        public bool $showLowestPrice = false,
        public bool $removeDataOnUninstall = false,
        #[Assert\PositiveOrZero]
        public int $lowStockThreshold = StoreSettings::DEFAULT_LOW_STOCK_THRESHOLD,
        #[Assert\Range(min: 1, max: 365)]
        public ?int $unpaidHoldDays = null,
        #[Assert\Range(min: 1, max: 365)]
        public ?int $returnDays = null,
        /** The least the items may come to, after discounts; null or empty for no least. */
        #[Assert\Regex('/^(\d{1,11}(\.\d{1,4})?)?$/', message: 'Use an amount with up to four decimals, such as 19.90.')]
        public ?string $minimumOrder = null,
    ) {}
}
