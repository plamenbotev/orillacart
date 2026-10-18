<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

/**
 * Postcodes a rate ships to: one postcode ("1000"), every postcode that starts the same ("SW1*"), or a range of
 * numeric postcodes, both ends included ("1000-1999"). Case and spaces do not matter, so "sw1a 1aa" is "SW1A1AA".
 * A range compares the digits a postcode starts with, so "94103-1234" is in "94000-94999".
 */
final readonly class PostcodePattern implements \Stringable
{
    private function __construct(
        private string $pattern,
        private ?string $prefix,
        private ?int $from,
        private ?int $to,
    ) {}

    public static function of(string $pattern): self
    {
        $normalized = self::normalize($pattern);
        if (preg_match('/^(\d{1,10})-(\d{1,10})$/', $normalized, $range) === 1) {
            if ((int) $range[1] > (int) $range[2]) {
                throw new ShippingRuleViolation(\sprintf('The postcode range "%s" ends before it starts.', $pattern), 'orillacart_invalid_postcode');
            }

            return new self($normalized, null, (int) $range[1], (int) $range[2]);
        }
        if (preg_match('/^([A-Z0-9]{1,10})\*$/', $normalized, $prefix) === 1) {
            return new self($normalized, $prefix[1], null, null);
        }
        if (preg_match('/^[A-Z0-9]{1,10}$/', $normalized) === 1) {
            return new self($normalized, null, null, null);
        }

        throw new ShippingRuleViolation(
            \sprintf('"%s" is not a postcode pattern; use a postcode such as 1000, a start such as SW1*, or a range such as 1000-1999.', $pattern),
            'orillacart_invalid_postcode',
        );
    }

    public function matches(string $postcode): bool
    {
        $postcode = self::normalize($postcode);
        if ($this->from !== null && $this->to !== null) {
            if (preg_match('/^\d{1,10}/', $postcode, $digits) !== 1) {
                return false;
            }

            return (int) $digits[0] >= $this->from && (int) $digits[0] <= $this->to;
        }
        $postcode = str_replace('-', '', $postcode);

        return $this->prefix !== null ? str_starts_with($postcode, $this->prefix) : $postcode === $this->pattern;
    }

    public function __toString(): string
    {
        return $this->pattern;
    }

    private static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }
}
