<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * An ISO 4217 currency code such as EUR or USD.
 */
final readonly class Currency implements \Stringable
{
    /** ISO 4217 minor units of the currencies without two: yen are whole, Kuwaiti dinars have three decimals. */
    private const array DECIMALS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0,
        'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        'CLF' => 4, 'UYW' => 4,
    ];

    public string $code;

    public function __construct(string $code)
    {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new RuleViolation('"%s" is not a currency code; use three capital letters such as EUR.', 'orillacart_invalid_currency', values: [$code]);
        }
        $this->code = $code;
    }

    /** The decimals amounts in the currency can have: its ISO 4217 minor units. */
    public function decimals(): int
    {
        return self::DECIMALS[$this->code] ?? 2;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
