<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

/**
 * Where the currency sign stands next to an amount: $100, $ 100, 100$ or 100 $.
 */
enum CurrencyPosition: string
{
    case Before = 'before';
    case BeforeWithSpace = 'before_space';
    case After = 'after';
    case AfterWithSpace = 'after_space';

    /** The amount with the sign in its place. */
    public function place(string $amount, string $sign): string
    {
        return match ($this) {
            self::Before => $sign . $amount,
            self::BeforeWithSpace => $sign . ' ' . $amount,
            self::After => $amount . $sign,
            self::AfterWithSpace => $amount . ' ' . $sign,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $position): string => $position->value, self::cases());
    }
}
