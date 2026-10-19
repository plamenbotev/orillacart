<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

/**
 * The weight and size units the shop measures in, and how they convert: for couriers that want kilograms and
 * centimetres whatever the shop uses, and for the shop when its units change.
 */
final class Units
{
    /** Kilograms in one unit. */
    public const array WEIGHT = ['kg' => 1.0, 'g' => 0.001, 'lb' => 0.45359237, 'oz' => 0.028349523125];

    /** Centimetres in one unit. */
    public const array LENGTH = ['cm' => 1.0, 'm' => 100.0, 'mm' => 0.1, 'in' => 2.54];

    /** The volume one kilogram of dimensional weight takes, as couriers mostly reckon it: 5000 cm³. */
    public const int DIMENSIONAL_DIVISOR = 5000;

    /** What one $from is in $to: 1000 for kg to g. */
    public static function weightFactor(string $from, string $to): float
    {
        return self::known(self::WEIGHT, $from) / self::known(self::WEIGHT, $to);
    }

    /** What one $from is in $to: 2.54 for in to cm. */
    public static function lengthFactor(string $from, string $to): float
    {
        return self::known(self::LENGTH, $from) / self::known(self::LENGTH, $to);
    }

    /** A decimal in one unit as a decimal in another, to four decimals: "2.2046" for 1 kg in lb. */
    public static function convert(string $value, float $factor): string
    {
        return self::decimal((float) $value * $factor);
    }

    /** A number as the shop writes measures: up to four decimals, without trailing zeros. */
    public static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /**
     * @param array<string, float> $units
     */
    private static function known(array $units, string $unit): float
    {
        return $units[$unit] ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a unit the shop measures in.', $unit));
    }
}
