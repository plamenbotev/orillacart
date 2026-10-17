<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * An amount in the shop's currency with up to four decimals, held exactly: 8.5 is 85000 ten-thousandths,
 * so sums never drift the way floats do. Negative amounts exist for price adjustments; a price is never negative.
 */
final readonly class Money implements \Stringable
{
    public const int DECIMALS = 4;
    /** Database columns hold amounts as DECIMAL(PRECISION, DECIMALS). */
    public const int PRECISION = 19;
    /** The largest amount the columns hold, with room left for sums. */
    public const string MAX = '99999999999.9999';

    private const int UNIT = 10 ** self::DECIMALS;

    private function __construct(public int $tenThousandths) {}

    /**
     * @param string $value a decimal such as "20", "8.5", "-1.25" or "0.0001"
     */
    public static function of(string $value): self
    {
        if (preg_match('/^(-?)(\d{1,11})(?:\.(\d{1,' . self::DECIMALS . '}))?$/', $value, $parts) !== 1) {
            throw new RuleViolation(
                \sprintf('"%s" is not an amount; use a number up to %s with up to %d decimals.', $value, self::MAX, self::DECIMALS),
                'orillacart_invalid_amount',
            );
        }
        $amount = (int) $parts[2] * self::UNIT + (int) str_pad($parts[3] ?? '', self::DECIMALS, '0');

        return new self($parts[1] === '-' ? -$amount : $amount);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->tenThousandths + $other->tenThousandths);
    }

    public function subtract(self $other): self
    {
        return new self($this->tenThousandths - $other->tenThousandths);
    }

    public function times(int $quantity): self
    {
        return new self($this->tenThousandths * $quantity);
    }

    /** This amount, or zero when it is below zero. */
    public function atLeastZero(): self
    {
        return $this->tenThousandths < 0 ? self::zero() : $this;
    }

    public function isNegative(): bool
    {
        return $this->tenThousandths < 0;
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
     * Fixed four decimals, as the database columns store it: "8.5000", "-1.2500".
     */
    public function toFixed(): string
    {
        $absolute = abs($this->tenThousandths);

        return \sprintf('%s%d.%0' . self::DECIMALS . 'd', $this->tenThousandths < 0 ? '-' : '', intdiv($absolute, self::UNIT), $absolute % self::UNIT);
    }

    /**
     * Shortest form: "8.5", "20", "-1.25".
     */
    public function __toString(): string
    {
        return rtrim(rtrim($this->toFixed(), '0'), '.');
    }
}
