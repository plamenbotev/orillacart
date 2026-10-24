<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * Postcodes a rate applies to: one postcode ("1000", "10-200"), every postcode that starts the same ("SW1*"), or a
 * range of numeric postcodes, both ends included and as long as each other ("1000-1999"). Case, spaces and dashes
 * inside a postcode do not matter, so "sw1a 1aa" is "SW1A1AA" and the Polish "10-200" is in "10000-10999". A
 * range compares as many digits as its ends have, so "94103-1234" is in "94000-94999"; two groups of digits of
 * different lengths are one postcode ("10-200", "100-0001", "94103-1234"), not a range.
 */
final readonly class PostcodePattern implements \Stringable
{
    private function __construct(
        private string $pattern,
        private ?string $prefix,
        private ?int $from,
        private ?int $to,
        private int $digits = 0,
    ) {}

    public static function of(string $pattern): self
    {
        $normalized = self::normalize($pattern);
        if (preg_match('/^(\d{1,10})-(\d{1,10})$/', $normalized, $range) === 1 && \strlen($range[1]) === \strlen($range[2])) {
            if ((int) $range[1] > (int) $range[2]) {
                throw new RuleViolation('The postcode range "%s" ends before it starts.', 'orillacart_invalid_postcode', values: [$pattern]);
            }

            return new self($normalized, null, (int) $range[1], (int) $range[2], \strlen($range[1]));
        }
        if (preg_match('/^\d{1,10}-\d{1,10}$/', $normalized) === 1) {
            // A postcode written with its dash: "10-200".
            return new self($normalized, null, null, null);
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
            // The digits it starts with, a dash between them or not: "10-200" is 10200, "94103-1234" 94103.
            if (preg_match('/^\d[\d-]*/', $postcode, $start) !== 1) {
                return false;
            }
            $digits = substr(str_replace('-', '', $start[0]), 0, $this->digits);

            return \strlen($digits) === $this->digits && (int) $digits >= $this->from && (int) $digits <= $this->to;
        }
        $postcode = str_replace('-', '', $postcode);

        return $this->prefix !== null ? str_starts_with($postcode, $this->prefix) : $postcode === str_replace('-', '', $this->pattern);
    }

    public function __toString(): string
    {
        return $this->pattern;
    }

    private static function normalize(string $value): string
    {
        // A range may be written with a dash, as hints show it.
        return strtoupper(str_replace(['–', '—'], '-', (string) preg_replace('/\s+/u', '', $value)));
    }
}
