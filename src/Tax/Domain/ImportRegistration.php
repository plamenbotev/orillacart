<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * How the shop sells goods into a scheme's countries: registered, with its number there, it charges their tax at
 * checkout on orders up to the limit; without a number, or over the limit, it charges none and the customer pays
 * at the border.
 */
final readonly class ImportRegistration
{
    public function __construct(
        public ImportScheme $scheme,
        /** The shop's number there; null when it is not registered and ships as exports. */
        public ?string $number,
        /** The limit in the shop's currency, what the goods are worth before shipping and tax. */
        public string $limit,
    ) {
        if ($number !== null && preg_match($scheme->numberPattern(), $number) !== 1) {
            throw new RuleViolation('"%1$s" is not a number for %2$s; it looks like %3$s.', 'orillacart_invalid_import_number', values: [$number, $scheme->label(), $scheme->numberExample()]);
        }
        if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $limit) !== 1 || (float) $limit <= 0) {
            throw new RuleViolation('The limit for %1$s is an amount such as %2$s.', 'orillacart_invalid_import_limit', values: [$scheme->label(), $scheme->limit()]);
        }
    }

    /** A number as typed, without spaces, dots or dashes, in capitals. */
    public static function normalize(string $number): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]/', '', $number));
    }
}
