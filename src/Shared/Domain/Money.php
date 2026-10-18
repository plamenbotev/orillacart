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

    /**
     * The part of this amount that $part is of $whole, rounded half away from zero: 10 spread by 1 of 3 is 3.3333.
     */
    public function proportion(self $part, self $whole): self
    {
        if ($whole->tenThousandths === 0) {
            return self::zero();
        }

        return new self((int) round($this->tenThousandths * ($part->tenThousandths / $whole->tenThousandths)));
    }

    /**
     * This share of the amount, such as the tax at a rate: 19 % of 15 is 2.85. Exact to four decimals,
     * rounded half away from zero.
     *
     * @param string $percent a decimal with up to four decimals, such as "19" or "7.25"
     */
    public function percent(string $percent): self
    {
        // Percent in ten-thousandths of a percent; the product is in 10^-8 units of money per 100.
        $share = self::percentShare($percent);

        if ($share !== 0 && abs($this->tenThousandths) > intdiv(\PHP_INT_MAX, $share)) {
            throw new RuleViolation('The amount is too large to take a percentage of.', 'orillacart_amount_too_large');
        }

        return new self(self::divideRounded($this->tenThousandths * $share, 100 * self::UNIT));
    }

    /**
     * The part of this amount, which includes tax at the given rates, that is the tax at one of them: of 12.00
     * including 20 %, the 20 % are 2.00. The rates add up, as a country's and a state's do.
     *
     * @param string       $percent one of the rates
     * @param list<string> $all     every rate the amount includes, $percent among them
     */
    public function includedPercent(string $percent, array $all): self
    {
        $share = self::percentShare($percent);
        $whole = 100 * self::UNIT + array_sum(array_map(self::percentShare(...), $all));
        if ($share !== 0 && abs($this->tenThousandths) > intdiv(\PHP_INT_MAX, $share)) {
            throw new RuleViolation('The amount is too large to take a percentage of.', 'orillacart_amount_too_large');
        }

        return new self(self::divideRounded($this->tenThousandths * $share, $whole));
    }

    /**
     * This amount, which includes tax at the given rates, without it: 12.00 including 20 % is 10.00 before tax.
     *
     * @param list<string> $all
     */
    public function withoutPercents(array $all): self
    {
        $tax = self::zero();
        foreach ($all as $percent) {
            $tax = $tax->add($this->includedPercent($percent, $all));
        }

        return $this->subtract($tax);
    }

    /** A percentage in ten-thousandths of a percent: "20" is 200000, "7.5" is 75000. */
    private static function percentShare(string $percent): int
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $percent, $parts) !== 1) {
            throw new RuleViolation(\sprintf('"%s" is not a percentage.', $percent), 'orillacart_invalid_percentage');
        }

        return (int) $parts[1] * self::UNIT + (int) str_pad($parts[2] ?? '', self::DECIMALS, '0');
    }

    /**
     * This amount with no more decimals than the currency shows, rounded half away from zero: 0.435 → 0.44.
     */
    public function rounded(int $decimals): self
    {
        if ($decimals < 0 || $decimals > self::DECIMALS) {
            throw new \InvalidArgumentException(\sprintf('Amounts round to 0 to %d decimals.', self::DECIMALS));
        }
        $step = 10 ** (self::DECIMALS - $decimals);

        return new self(self::divideRounded($this->tenThousandths, $step) * $step);
    }

    public function compare(self $other): int
    {
        return $this->tenThousandths <=> $other->tenThousandths;
    }

    /**
     * Fixed decimals for display or a payment provider: "8.50" with two, "9" with none. Rounds first.
     */
    public function format(int $decimals): string
    {
        $fixed = $this->rounded($decimals)->toFixed();

        return $decimals === 0 ? substr($fixed, 0, -self::DECIMALS - 1) : substr($fixed, 0, \strlen($fixed) - self::DECIMALS + $decimals);
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

    private static function divideRounded(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);
        $remainder = $value % $divisor;
        if (2 * abs($remainder) >= $divisor) {
            $quotient += $value < 0 ? -1 : 1;
        }

        return $quotient;
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
