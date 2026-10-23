<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * Who a tax is owed to, as a return is filed: a country, one of its states or regions, or a place within one (a
 * county, a city, a district), with the code the shop files it under.
 */
final readonly class TaxJurisdiction
{
    public const string COUNTRY = 'country';
    public const string REGION = 'region';
    public const string LOCAL = 'local';
    public const array LEVELS = [self::COUNTRY, self::REGION, self::LOCAL];

    /**
     * @param string      $country ISO 3166-1 alpha-2
     * @param string|null $region  the state's code without the country, for a region's or a local tax
     * @param string      $level   one of LEVELS
     * @param string      $code    the code it is filed under: the rate's own, else built from the place ("US-TX")
     * @param string      $name    as returns and invoices name it
     */
    public function __construct(
        public string $country,
        public ?string $region,
        public string $level,
        public string $code,
        public string $name,
    ) {}
}
