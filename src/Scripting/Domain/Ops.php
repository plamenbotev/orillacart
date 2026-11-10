<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * What the generated PHP does with values: every operator, member read, filter and test of the language is one of
 * these, so a script can do nothing PHP can that the language cannot. Values are null, bool, int, float, string,
 * Money, and arrays (lists and maps alike, as PHP has them); nothing else reaches a script.
 */
final class Ops
{
    /** The filters a script may use, Twig's names. */
    public const array FILTERS = [
        'abs', 'amount', 'capitalize', 'default', 'first', 'join', 'json', 'keys', 'last', 'length', 'lower', 'map', 'merge',
        'number', 'replace', 'reverse', 'round', 'slice', 'sort', 'split', 'title', 'trim', 'upper', 'values',
    ];
    /** The bytes a filter may make when the caller does not say: the shop's ceiling for one run. */
    public const int ROOM = 16 * 1024 * 1024;
    /** How deep a value may nest for `json` and the host: deeper is a script's mistake, or a value built to blow up. */
    public const int DEPTH = 64;
    /** The decimals `number`, `amount` and `round` take, either way. */
    public const int DECIMALS = 20;
    /** The tests a script may use. */
    public const array TESTS = ['defined', 'empty', 'even', 'iterable', 'list', 'map', 'money', 'null', 'number', 'odd', 'string'];

    public static function truthy(mixed $value): bool
    {
        if ($value instanceof Money) {
            return !$value->isZero();
        }

        return \is_array($value) ? $value !== [] : (bool) $value;
    }

    /** `a.name`, `a[key]`: null when there is no such member, as Twig without strict variables. */
    public static function get(mixed $object, mixed $key): mixed
    {
        if (\is_array($object)) {
            if (\is_int($key) || \is_string($key)) {
                return $object[$key] ?? null;
            }
            if (\is_float($key) && $key === floor($key)) {
                return $object[(int) $key] ?? null;
            }

            return null;
        }
        if (\is_string($object) && \is_int($key)) {
            return mb_substr($object, $key, 1) === '' ? null : mb_substr($object, $key, 1);
        }
        if ($object instanceof Money && \is_string($key)) {
            return match ($key) {
                'amount' => $object->toFixed(),
                default => null,
            };
        }

        return null;
    }

    /**
     * `a.b.c = value` on a copy of a: PHP's arrays are values, so the caller keeps the result.
     *
     * @param list<mixed> $path
     *
     * @return array<mixed>
     */
    public static function assign(mixed $root, array $path, mixed $value): array
    {
        if (!\is_array($root)) {
            throw new ScriptError('Only a list or a map takes a member');
        }
        $key = array_shift($path);
        if (!\is_int($key) && !\is_string($key)) {
            throw new ScriptError('A key is a string or a number');
        }
        if ($path === []) {
            $root[$key] = $value;

            return $root;
        }
        $root[$key] = self::assign($root[$key] ?? [], $path, $value);

        return $root;
    }

    public static function eq(mixed $a, mixed $b): bool
    {
        if ($a instanceof Money || $b instanceof Money) {
            return $a instanceof Money && $b instanceof Money && $a->equals($b);
        }
        if (\is_int($a) && \is_float($b) || \is_float($a) && \is_int($b)) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    /** For `<`, `>`, `<=`, `>=`: numbers with numbers, strings with strings, money with money. */
    public static function cmp(mixed $a, mixed $b): int
    {
        if ($a instanceof Money && $b instanceof Money) {
            return $a->compare($b);
        }
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            return $a <=> $b;
        }
        if (\is_string($a) && \is_string($b)) {
            return strcmp($a, $b);
        }

        throw new ScriptError(\sprintf('Cannot compare %s with %s', self::kind($a), self::kind($b)));
    }

    public static function add(mixed $a, mixed $b): int|float|Money
    {
        if ($a instanceof Money && $b instanceof Money) {
            return $a->add($b);
        }

        return self::number($a, '+') + self::number($b, '+');
    }

    public static function sub(mixed $a, mixed $b): int|float|Money
    {
        if ($a instanceof Money && $b instanceof Money) {
            return $a->subtract($b);
        }

        return self::number($a, '-') - self::number($b, '-');
    }

    public static function mul(mixed $a, mixed $b): int|float|Money
    {
        if ($a instanceof Money && !$b instanceof Money) {
            return self::times($a, self::number($b, '*'));
        }
        if ($b instanceof Money && !$a instanceof Money) {
            return self::times($b, self::number($a, '*'));
        }

        return self::number($a, '*') * self::number($b, '*');
    }

    public static function div(mixed $a, mixed $b): int|float|Money
    {
        $divisor = self::number($b, '/');
        if ((float) $divisor === 0.0) {
            throw new ScriptError('Division by zero');
        }
        if ($a instanceof Money) {
            return self::times($a, 1 / $divisor);
        }
        $result = self::number($a, '/') / $divisor;

        return \is_int($result) ? $result : ($result === floor($result) && abs($result) < PHP_INT_MAX ? (int) $result : $result);
    }

    public static function intdiv(mixed $a, mixed $b): int
    {
        $divisor = self::number($b, '//');
        if ((float) $divisor === 0.0) {
            throw new ScriptError('Division by zero');
        }

        return (int) floor(self::number($a, '//') / $divisor);
    }

    public static function mod(mixed $a, mixed $b): int|float
    {
        $divisor = self::number($b, '%');
        if ((float) $divisor === 0.0) {
            throw new ScriptError('Division by zero');
        }

        $dividend = self::number($a, '%');

        return \is_int($dividend) && \is_int($divisor) ? $dividend % $divisor : fmod($dividend, $divisor);
    }

    public static function neg(mixed $a): int|float|Money
    {
        if ($a instanceof Money) {
            return Money::zero()->subtract($a);
        }

        return -self::number($a, '-');
    }

    /** `a ~ b`: strings; numbers and money by their text; null as nothing. */
    public static function concat(mixed $a, mixed $b): string
    {
        return self::text($a) . self::text($b);
    }

    public static function in(mixed $needle, mixed $haystack): bool
    {
        if (\is_array($haystack)) {
            foreach ($haystack as $item) {
                if (self::eq($needle, $item)) {
                    return true;
                }
            }

            return false;
        }
        if (\is_string($haystack)) {
            return str_contains($haystack, self::text($needle));
        }

        throw new ScriptError(\sprintf('"in" looks in a list, a map or a string, not %s', self::kind($haystack)));
    }

    public static function startsWith(mixed $a, mixed $b): bool
    {
        return str_starts_with(self::text($a), self::text($b));
    }

    public static function endsWith(mixed $a, mixed $b): bool
    {
        return str_ends_with(self::text($a), self::text($b));
    }

    /**
     * `a..b`: the integers from a to b, either way; at most 10 000 of them.
     *
     * @return list<int>
     */
    public static function range(mixed $a, mixed $b): array
    {
        $from = (int) self::number($a, '..');
        $to = (int) self::number($b, '..');
        if (abs($to - $from) >= 10000) {
            throw new ScriptError('A range holds at most 10 000 numbers');
        }

        return range($from, $to);
    }

    /**
     * What `for` walks: a list or a map; null walks nothing.
     *
     * @return array<mixed>
     */
    public static function iterable(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!\is_array($value)) {
            throw new ScriptError(\sprintf('"for" walks a list or a map, not %s', self::kind($value)));
        }

        return $value;
    }

    /**
     * `f(args)` where f is a value: a function the script made.
     *
     * @param list<mixed> $args
     */
    public static function invoke(mixed $callable, array $args): mixed
    {
        if (!$callable instanceof \Closure) {
            throw new ScriptError(\sprintf('Cannot call %s', self::kind($callable)));
        }

        return $callable(...$args);
    }

    /** A map's key from an expression: a string or a whole number. */
    public static function key(mixed $value): int|string
    {
        if (\is_int($value) || \is_string($value)) {
            return $value;
        }
        if (\is_float($value) && $value === floor($value)) {
            return (int) $value;
        }

        throw new ScriptError(\sprintf('A key is a string or a number, not %s', self::kind($value)));
    }

    /** `fail x`: x an error value from unavailable(), refused() or error(), or a message. */
    /** `assert test, message`: a failure of kind `assertion`, naming the line, when the test is false. */
    public static function assert(mixed $test, mixed $message, int $line): void
    {
        if (!self::truthy($test)) {
            throw new ScriptFailure(ScriptFailure::ASSERTION, $message === null ? \sprintf('Assertion failed at line %d', $line) : self::text($message), ['line' => $line]);
        }
    }

    public static function failure(mixed $value): ScriptFailure
    {
        if (\is_array($value) && \is_string($value['kind'] ?? null) && \is_string($value['message'] ?? null)) {
            return new ScriptFailure($value['kind'], $value['message'], $value['data'] ?? null);
        }

        return new ScriptFailure(ScriptFailure::ERROR, self::text($value));
    }

    /**
     * A filter. What it would make is predicted first where it can grow past its input (`json`, `join`, `split`,
     * `reverse`, `replace`), so a run out of room stops before the memory is spent, not after.
     *
     * @param list<mixed> $args
     * @param int         $room the bytes the run may still make
     */
    public static function filter(string $name, mixed $value, array $args, int $room = self::ROOM): mixed
    {
        switch ($name) {
            case 'abs':
                return $value instanceof Money ? ($value->isNegative() ? self::neg($value) : $value) : abs(self::number($value, 'abs'));
            case 'capitalize':
                return mb_strtoupper(mb_substr(self::text($value), 0, 1)) . mb_strtolower(mb_substr(self::text($value), 1));
            case 'default':
                return self::truthy($value) ? $value : ($args[0] ?? '');
            case 'first':
                return \is_array($value) ? ($value === [] ? null : $value[array_key_first($value)]) : mb_substr(self::text($value), 0, 1);
            case 'join':
                $separator = self::text($args[0] ?? '');
                $items = self::iterable($value);
                self::fits(max(0, \count($items) - 1) * \strlen($separator), $room);
                $size = 0;
                $texts = [];
                foreach ($items as $item) {
                    $texts[] = $text = self::text($item);
                    self::fits($size += \strlen($text), $room);
                }

                return implode($separator, $texts);
            case 'json':
                return (string) json_encode(self::plain($value, $room), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            case 'keys':
                return array_keys(self::iterable($value));
            case 'last':
                return \is_array($value) ? ($value === [] ? null : $value[array_key_last($value)]) : mb_substr(self::text($value), -1);
            case 'length':
                return \is_array($value) ? \count($value) : mb_strlen(self::text($value));
            case 'lower':
                return mb_strtolower(self::text($value));
            case 'map':
                $callback = $args[0] ?? null;
                if (!$callback instanceof \Closure) {
                    throw new ScriptError('"map" takes a function');
                }

                return array_map($callback, self::iterable($value));
            case 'merge':
                return array_merge(self::iterable($value), self::iterable($args[0] ?? []));
            case 'number':
                return number_format(self::number($value, 'number'), self::decimals($args[0] ?? 0, 'number', 0), self::text($args[1] ?? '.'), self::text($args[2] ?? ','));
            case 'amount':
                // Money (or a number) as decimal text for an API or a form: "1450.00", no thousands separator.
                return number_format(self::number($value, 'amount'), self::decimals($args[0] ?? 2, 'amount', 0), '.', '');
            case 'replace':
                $text = self::text($value);
                $pairs = self::iterable($args[0] ?? []);
                $pairs = array_combine(array_map(strval(...), array_keys($pairs)), array_map(self::text(...), $pairs));
                // Each match is replaced once, so the text plus every match's replacement bounds what it makes.
                $size = \strlen($text);
                foreach ($pairs as $from => $to) {
                    if ($from !== '' && \strlen($to) > \strlen($from)) {
                        self::fits($size += substr_count($text, $from) * (\strlen($to) - \strlen($from)), $room);
                    }
                }

                return strtr($text, $pairs);
            case 'reverse':
                if (\is_array($value)) {
                    return array_reverse($value, !array_is_list($value));
                }
                $text = self::text($value);
                self::fits(mb_strlen($text) * 16 + \strlen($text), $room);

                return implode('', array_reverse(mb_str_split($text)));
            case 'round':
                if ($value instanceof Money) {
                    return $value->rounded(self::decimals($args[0] ?? 0, 'round', 0, Money::DECIMALS));
                }

                return round(self::number($value, 'round'), self::decimals($args[0] ?? 0, 'round', -self::DECIMALS));
            case 'slice':
                $start = (int) self::number($args[0] ?? 0, 'slice');
                $length = isset($args[1]) ? (int) self::number($args[1], 'slice') : null;

                return \is_array($value) ? \array_slice($value, $start, $length, !array_is_list($value)) : mb_substr(self::text($value), $start, $length);
            case 'sort':
                $sorted = self::iterable($value);
                $callback = $args[0] ?? null;
                if ($callback instanceof \Closure) {
                    uasort($sorted, static fn(mixed $a, mixed $b): int => (int) self::number($callback($a, $b), 'sort'));
                } else {
                    uasort($sorted, self::cmp(...));
                }

                return \is_array($value) && array_is_list($value) ? array_values($sorted) : $sorted;
            case 'split':
                $by = self::text($args[0] ?? '');
                $text = self::text($value);
                self::fits(($by === '' ? mb_strlen($text) : substr_count($text, $by) + 1) * 16 + \strlen($text), $room);

                return $by === '' ? mb_str_split($text) : explode($by, $text);
            case 'title':
                return mb_convert_case(self::text($value), MB_CASE_TITLE);
            case 'trim':
                return trim(self::text($value), self::text($args[0] ?? " \n\r\t\v\0"));
            case 'upper':
                return mb_strtoupper(self::text($value));
            case 'values':
                return array_values(self::iterable($value));
        }

        throw new ScriptError(\sprintf('Unknown filter "%s"', $name));
    }

    /**
     * @param list<mixed> $args
     */
    public static function test(string $name, mixed $value, array $args): bool
    {
        return match ($name) {
            'defined' => $value !== null,
            'empty' => !self::truthy($value),
            'even' => (int) self::number($value, 'even') % 2 === 0,
            'odd' => (int) self::number($value, 'odd') % 2 !== 0,
            'iterable' => \is_array($value),
            'list' => \is_array($value) && array_is_list($value),
            'map' => \is_array($value) && !array_is_list($value),
            'money' => $value instanceof Money,
            'null' => $value === null,
            'number' => \is_int($value) || \is_float($value),
            'string' => \is_string($value),
            default => throw new ScriptError(\sprintf('Unknown test "%s"', $name)),
        };
    }

    /** A value as text: for `~`, messages and keys. */
    public static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value) || \is_float($value) || \is_string($value)) {
            return (string) $value;
        }
        if ($value instanceof Money) {
            return $value->toFixed();
        }

        throw new ScriptError(\sprintf('Cannot make text of %s', self::kind($value)));
    }

    /**
     * A value as JSON takes it: money as its amount's text, closures refused. Its size is counted first, as the
     * run counts bytes, since a list holding itself twice over is small until it is copied out.
     *
     * @param int $room the bytes the copy may take
     */
    public static function plain(mixed $value, int $room = self::ROOM): mixed
    {
        $size = 0;
        self::measure($value, $room, $size, 0);

        return self::copy($value);
    }

    /**
     * Adds a value's bytes to the size, as the run counts them (a string its length, a list 16 per item), and
     * stops at the room or the depth; it walks no further than the room, however large the value would be.
     */
    private static function measure(mixed $value, int $room, int &$size, int $depth): void
    {
        if (\is_string($value)) {
            self::fits($size += \strlen($value), $room);
        } elseif (\is_array($value)) {
            if ($depth >= self::DEPTH) {
                throw new BudgetExceeded(\sprintf('A value nests deeper than %d levels', self::DEPTH));
            }
            self::fits($size += \count($value) * 16, $room);
            foreach ($value as $item) {
                self::measure($item, $room, $size, $depth + 1);
            }
        }
    }

    /** Stops the run when what a filter would make is more than the bytes it has left. */
    private static function fits(int $size, int $room): void
    {
        if ($size > $room) {
            throw new BudgetExceeded(\sprintf('The script would make more than its %d bytes of values', $room));
        }
    }

    /** The decimals a filter was given, within its bounds. */
    private static function decimals(mixed $value, string $filter, int $least, int $most = self::DECIMALS): int
    {
        $decimals = self::number($value, $filter);
        if ($decimals < $least || $decimals > $most) {
            throw new ScriptError(\sprintf('"%s" takes %d to %d decimals', $filter, $least, $most));
        }

        return (int) $decimals;
    }

    private static function copy(mixed $value): mixed
    {
        if ($value instanceof Money) {
            return $value->toFixed();
        }
        if (\is_array($value)) {
            return array_map(self::copy(...), $value);
        }
        if ($value instanceof \Closure) {
            throw new ScriptError('A function cannot be made into data');
        }

        return $value;
    }

    /** For messages: "a string", "a list", "money". */
    public static function kind(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => 'a boolean',
            \is_int($value), \is_float($value) => 'a number',
            \is_string($value) => 'a string',
            $value instanceof Money => 'money',
            \is_array($value) => array_is_list($value) ? 'a list' : 'a map',
            $value instanceof \Closure => 'a function',
            default => 'a value',
        };
    }

    private static function number(mixed $value, string $op): int|float
    {
        if (\is_int($value) || \is_float($value)) {
            return $value;
        }
        if ($value instanceof Money) {
            return (float) $value->toFixed();
        }
        if (\is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') || stripos($value, 'e') !== false ? (float) $value : (int) $value;
        }
        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        throw new ScriptError(\sprintf('"%s" needs a number, not %s', $op, self::kind($value)));
    }

    private static function times(Money $money, int|float $factor): Money
    {
        // Money::times takes whole quantities; a fraction goes through a (positive) rate, rounded as the shop rounds.
        if (\is_int($factor)) {
            return $money->times($factor);
        }
        if ($factor === 0.0) {
            return Money::zero();
        }
        $scaled = $money->at(number_format(abs($factor), 8, '.', ''));

        return $factor < 0 ? Money::zero()->subtract($scaled) : $scaled;
    }
}
