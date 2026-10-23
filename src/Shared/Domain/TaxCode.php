<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * What a tax service calls a kind of goods (Avalara's "PC040100", Stripe's "txcd_99999999", TaxJar's "20010"):
 * the shop keeps it on tax groups and products, and sends it with each line it asks a service about.
 */
final class TaxCode
{
    public const string PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,39}$/';
    public const string MESSAGE = 'A tax code has up to 40 letters, digits, dots, dashes, colons or underscores, such as PC040100 or txcd_99999999.';

    private function __construct() {}

    /**
     * @return string|null the code, trimmed; null for none
     *
     * @throws \InvalidArgumentException for one that cannot be a code
     */
    public static function valid(?string $code): ?string
    {
        $code = $code === null ? null : trim($code);
        if ($code === null || $code === '') {
            return null;
        }
        if (preg_match(self::PATTERN, $code) !== 1) {
            throw new \InvalidArgumentException(self::MESSAGE);
        }

        return $code;
    }
}
