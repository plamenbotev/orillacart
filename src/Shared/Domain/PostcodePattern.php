<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * Postcodes a rate applies to: one postcode ("1000", "10-200"), every postcode that starts the same ("SW1*"), or a
 * range of numeric postcodes, both ends included ("1000-1999"). Case, spaces and dashes inside a postcode do not
 * matter, so "sw1a 1aa" is "SW1A1AA".
 *
 * A range compares numbers, so a range saved by a spreadsheet without its leading zeros ("501-999" for
 * "00501-00999") still means those postcodes, and its ends may differ in length ("1000-99999"). A postcode written
 * with a dash is in a range by its digits together or by its first group: the Polish "10-200" is in "10000-10999",
 * the US "94103-1234" in "94000-94999", the Portuguese "1000-001" in "1000-1999". Two groups of digits in the shape of
 * a postcode with a dash (two and three digits for Poland, three and four for Japan, four and three for Portugal,
 * five and three for Brazil, five and four for the US) are that one postcode, not a range; written with three dots
 * ("10...200", as WooCommerce writes ranges) they are always a range. A country written before the postcode, as
 * Lithuania, Latvia, Luxembourg and Moldova write it ("LT-01100", "L-1009"), is left out, of postcodes and patterns.
 */
final readonly class PostcodePattern implements \Stringable
{
    /** The lengths of the two groups of a postcode written with a dash. */
    private const array WITH_DASH = [[2, 3], [3, 4], [4, 3], [5, 3], [5, 4]];

    private function __construct(
        private string $pattern,
        private ?string $prefix,
        private ?int $from,
        private ?int $to,
    ) {}

    public static function of(string $pattern): self
    {
        $normalized = self::normalize($pattern);
        if (preg_match('/^(\d{1,10})(-|\.\.\.)(\d{1,10})$/', $normalized, $groups) === 1) {
            $dash = \in_array([\strlen($groups[1]), \strlen($groups[3])], self::WITH_DASH, true);
            if ($dash && $groups[2] === '-') {
                // A postcode written with its dash: "10-200".
                return new self($normalized, null, null, null);
            }
            if ((int) $groups[1] > (int) $groups[3]) {
                throw new RuleViolation('The postcode range "%s" ends before it starts.', 'orillacart_invalid_postcode', values: [$pattern]);
            }

            // Kept with a dash, unless that would read as one postcode.
            return new self($dash ? $normalized : "{$groups[1]}-{$groups[3]}", null, (int) $groups[1], (int) $groups[3]);
        }
        if (preg_match('/^([A-Z0-9]{1,10})\*$/', $normalized, $prefix) === 1) {
            return new self($normalized, $prefix[1], null, null);
        }
        if (preg_match('/^[A-Z0-9]{1,10}$/', $normalized) === 1) {
            return new self($normalized, null, null, null);
        }

        throw new RuleViolation(
            '"%s" is not a postcode pattern; use a postcode such as 1000, a start such as SW1*, or a range such as 1000-1999.',
            'orillacart_invalid_postcode',
            values: [$pattern],
        );
    }

    public function matches(string $postcode): bool
    {
        $postcode = self::normalize($postcode);
        if ($this->from !== null && $this->to !== null) {
            // The digits it starts with: "1234AB" is 1234, "10-200" 10200 or 10.
            if (preg_match('/^(\d{1,10})(?:-(\d{1,10}))?/', $postcode, $start) !== 1) {
                return false;
            }
            $numbers = [(int) ($start[1] . ($start[2] ?? ''))];
            if (isset($start[2])) {
                $numbers[] = (int) $start[1];
            }
            foreach ($numbers as $number) {
                if ($number >= $this->from && $number <= $this->to) {
                    return true;
                }
            }

            return false;
        }
        $postcode = str_replace('-', '', $postcode);

        return $this->prefix !== null ? str_starts_with($postcode, $this->prefix) : $postcode === str_replace('-', '', $this->pattern);
    }

    /**
     * Whether some postcode is matched by both patterns: "902*" and "90210" are, "1000-1999" and "15*" are, "SW1*"
     * and "SW2*" are not.
     */
    public function overlaps(self $other): bool
    {
        if ($this->from !== null && $other->from !== null) {
            return $this->from <= (int) $other->to && $other->from <= (int) $this->to;
        }
        if ($this->from !== null || $other->from !== null) {
            [$range, $pattern] = $this->from !== null ? [$this, $other] : [$other, $this];

            return $pattern->prefix === null ? $range->matches($pattern->pattern) : $range->rangeMeetsStart($pattern->prefix);
        }
        $one = str_replace('-', '', $this->prefix ?? $this->pattern);
        $two = str_replace('-', '', $other->prefix ?? $other->pattern);
        if ($this->prefix !== null && $other->prefix !== null) {
            return str_starts_with($one, $two) || str_starts_with($two, $one);
        }
        if ($this->prefix !== null || $other->prefix !== null) {
            return $this->prefix !== null ? str_starts_with($two, $one) : str_starts_with($one, $two);
        }

        return $one === $two;
    }

    /** Whether a postcode starting so can be in this range: its leading digits, with more digits after them. */
    private function rangeMeetsStart(string $start): bool
    {
        if (preg_match('/^\d+/', $start, $digits) !== 1) {
            return false;
        }
        $number = (int) $digits[0];
        if ($digits[0] !== $start) {
            // Letters follow: the postcode's number is those digits.
            return $number >= (int) $this->from && $number <= (int) $this->to;
        }
        // All digits: the postcode's number is those, or those followed by more (up to ten digits in all).
        for ($more = 0, $step = 1; \strlen($digits[0]) + $more <= 10; ++$more, $step *= 10) {
            if ($number * $step <= (int) $this->to && ($number + 1) * $step - 1 >= (int) $this->from) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return $this->pattern;
    }

    private static function normalize(string $value): string
    {
        // A range may be written with a dash, as hints show it.
        $value = strtoupper(str_replace(['–', '—'], '-', (string) preg_replace('/\s+/u', '', $value)));

        // Without the country some countries write before it (LT-01100, LV-1050, L-1009, MD-2001): the digits
        // are the postcode.
        return (string) preg_replace('/^[A-Z]{1,2}-(?=\d)/', '', $value);
    }
}
