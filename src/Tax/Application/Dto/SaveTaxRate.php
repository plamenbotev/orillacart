<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\TaxRateDetails;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: add a rate to a tax group, or change one. The percentage is a decimal string, such as "7.25",
 * so it arrives exactly as typed.
 */
final readonly class SaveTaxRate
{
    public function __construct(
        #[Assert\Regex('/^[A-Z]{2}$/', message: 'Use a two-letter country code such as DE.')]
        public string $country,
        #[Assert\Regex('/^[A-Z0-9]{1,3}$/', message: 'Use the state code, such as CA.')]
        public ?string $state,
        #[Assert\Regex('/^\d{1,3}(\.\d{1,4})?$/', message: 'Use a number from 0 to 999.9999 with up to four decimals, such as 7.25.')]
        public string $percent,
        #[Assert\Length(max: TaxRateDetails::MAX_NAME_LENGTH)]
        public string $name = '',
        #[Assert\Range(min: 0, max: 1000)]
        public int $priority = 0,
        /** @var list<string> postcodes such as 1000, SW1* or 1000-1999; empty for every postcode */
        #[Assert\Count(max: TaxRateDetails::MAX_POSTCODES)]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 21)])]
        public array $postcodes = [],
        public bool $compound = false,
        /** The code the shop files this tax under (a county's, a city's); empty to build one from the place. */
        #[Assert\Regex('/^[A-Za-z0-9._-]{1,40}$/', message: 'Use up to 40 letters, digits, dots, dashes or underscores.')]
        public ?string $code = null,
        /** @var list<string> the cities it applies to; empty for every city */
        #[Assert\Count(max: TaxRateDetails::MAX_CITIES)]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: TaxRateDetails::MAX_CITY_LENGTH)])]
        public array $cities = [],
        /** Charged on shipping too. */
        public bool $shipping = true,
    ) {}
}
