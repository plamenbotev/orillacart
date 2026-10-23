<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Tax;

/**
 * One rate of one jurisdiction on one line, as a service worked it out or an order kept it.
 */
final readonly class TaxLine
{
    public const string COUNTRY = 'country';
    public const string REGION = 'region';
    public const string LOCAL = 'local';

    /**
     * @param string      $percent  decimal, up to four places: "7.25"
     * @param string      $country  ISO 3166-1 alpha-2
     * @param string|null $region   the state's code, for a state's or a local tax
     * @param string      $level    COUNTRY, REGION or LOCAL
     * @param string      $code     the code the shop files it under: the service's jurisdiction code
     * @param string      $taxable  what it is charged on, decimal
     * @param string      $amount   the tax, decimal, in the currency's places
     * @param bool        $exempt   not charged under the customer's exemption; the amount is 0
     */
    public function __construct(
        public string $name,
        public string $percent,
        public string $country,
        public ?string $region,
        public string $level,
        public string $code,
        public string $jurisdiction,
        public string $taxable,
        public string $amount,
        public bool $exempt = false,
    ) {}
}
