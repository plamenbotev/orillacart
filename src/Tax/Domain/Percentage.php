<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * A tax percentage with up to four decimals, held exactly: 7.25 % is 72500 ten-thousandths of a percent,
 * so adding rates never drifts the way floats do.
 */
final readonly class Percentage implements \Stringable
{
    public const int DECIMALS = 4;
    /** Below 1000 %, the largest value the tax_rates.percent column holds. */
    public const string MAX = '999.9999';

    private const int UNIT = 10 ** self::DECIMALS;

    private function __construct(public int $tenThousandths) {}

    /**
     * @param string $value a decimal such as "20", "7.25" or "0.375"
     */
    public static function of(string $value): self
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,' . self::DECIMALS . '}))?$/', $value, $parts) !== 1) {
            throw new RuleViolation(
                \sprintf('"%s" is not a tax percentage; use a number from 0 to %s with up to %d decimals.', $value, self::MAX, self::DECIMALS),
                'orillacart_invalid_percentage',
            );
        }

        return new self((int) $parts[1] * self::UNIT + (int) str_pad($parts[2] ?? '', self::DECIMALS, '0'));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->tenThousandths + $other->tenThousandths);
    }

    public function isZero(): bool
    {
        return $this->tenThousandths === 0;
    }

    public function equals(self $other): bool
    {
        return $this->tenThousandths === $other->tenThousandths;
    }

    /**
     * Fixed four decimals, as the database column stores it: "7.2500".
     */
    public function toFixed(): string
    {
        return \sprintf('%d.%0' . self::DECIMALS . 'd', intdiv($this->tenThousandths, self::UNIT), $this->tenThousandths % self::UNIT);
    }

    /**
     * Shortest form: "7.25", "20".
     */
    public function __toString(): string
    {
        return rtrim(rtrim($this->toFixed(), '0'), '.');
    }
}
