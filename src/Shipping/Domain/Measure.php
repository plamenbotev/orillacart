<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

/**
 * A weight, length or volume in the shop's units, zero or more with up to four decimals, held exactly so
 * a parcel of 4 × 0.5 kg weighs exactly 2 kg and lands in the right rate.
 */
final readonly class Measure implements \Stringable
{
    public const int DECIMALS = 4;

    private const int UNIT = 10 ** self::DECIMALS;

    private function __construct(public int $tenThousandths) {}

    /**
     * @param string $value a decimal such as "2", "0.5" or "12.25"
     */
    public static function of(string $value): self
    {
        if (preg_match('/^(\d{1,14})(?:\.(\d{1,' . self::DECIMALS . '}))?$/', $value, $parts) !== 1) {
            throw new ShippingRuleViolation(\sprintf('"%s" is not a measure; use a number of zero or more with up to %d decimals.', $value, self::DECIMALS), 'orillacart_invalid_measure');
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

    public function times(int $count): self
    {
        return new self($this->tenThousandths * $count);
    }

    /** The product of two measures, such as length × width, to four decimals (rounded down). */
    public function multiply(self $other): self
    {
        if ($other->tenThousandths !== 0 && $this->tenThousandths > intdiv(\PHP_INT_MAX, $other->tenThousandths)) {
            throw new ShippingRuleViolation('The measure is too large.', 'orillacart_measure_too_large');
        }

        return new self(intdiv($this->tenThousandths * $other->tenThousandths, self::UNIT));
    }

    public function compare(self $other): int
    {
        return $this->tenThousandths <=> $other->tenThousandths;
    }

    public function toFixed(): string
    {
        return \sprintf('%d.%0' . self::DECIMALS . 'd', intdiv($this->tenThousandths, self::UNIT), $this->tenThousandths % self::UNIT);
    }

    /** Shortest form: "2", "0.5". */
    public function __toString(): string
    {
        return rtrim(rtrim($this->toFixed(), '0'), '.');
    }
}
