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
            throw new RuleViolation(\sprintf('"%s" is not a number for %s; it looks like %s.', $number, $scheme->label(), $scheme->numberExample()), 'orillacart_invalid_import_number');
        }
        if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $limit) !== 1 || (float) $limit <= 0) {
            throw new RuleViolation(\sprintf('The limit for %s is an amount such as %s.', $scheme->label(), $scheme->limit()), 'orillacart_invalid_import_limit');
        }
    }

    /** A number as typed, without spaces, dots or dashes, in capitals. */
    public static function normalize(string $number): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]/', '', $number));
    }
}
