<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use OrillaCart\Shared\Domain\PostcodePattern;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * What a tax rate says: where it applies, how much, under which name, and its place among the rates
 * that apply together. A compound rate is charged on the price and the taxes listed before it, as Quebec's
 * QST once was on top of GST.
 */
final readonly class TaxRateDetails
{
    public const int MAX_NAME_LENGTH = 64;

    public const int MAX_POSTCODES = 500;

    /**
     * @param string      $country  ISO 3166-1 alpha-2
     * @param string|null $state    ISO 3166-2 subdivision code without the country prefix; null for the whole country
     * @param int         $priority lower comes first when the rates of one location are listed
     * @param list<PostcodePattern> $postcodes the postcodes it applies to within the country or state; empty for all
     */
    public function __construct(
        public string $country,
        public ?string $state,
        public Percentage $percent,
        public string $name = '',
        public int $priority = 0,
        public array $postcodes = [],
        public bool $compound = false,
        /** The code the shop files this tax under, such as a county's or city's; null to build one from the place. */
        public ?string $code = null,
    ) {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new RuleViolation(\sprintf('"%s" is not a country code.', $country), 'orillacart_invalid_country');
        }
        if ($state !== null && preg_match('/^[A-Z0-9]{1,3}$/', $state) !== 1) {
            throw new RuleViolation(\sprintf('"%s" is not a state code.', $state), 'orillacart_invalid_state');
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new RuleViolation(\sprintf('Tax names can have up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_tax_name');
        }
        if ($priority < 0) {
            throw new RuleViolation('The priority cannot be negative.', 'orillacart_invalid_priority');
        }
        if ($code !== null && preg_match('/^[A-Za-z0-9._-]{1,40}$/', $code) !== 1) {
            throw new RuleViolation('A tax code has 1 to 40 letters, digits, dots, dashes or underscores.', 'orillacart_invalid_tax_code');
        }
        if (\count($postcodes) > self::MAX_POSTCODES) {
            throw new RuleViolation(\sprintf('A tax rate can list up to %d postcodes.', self::MAX_POSTCODES), 'orillacart_too_many_postcodes');
        }
    }
}
