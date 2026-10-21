<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Domain;

/**
 * A gift card's code: sixteen characters in groups of four, "7KQ2-M9XD-…", from letters and digits that cannot
 * be mistaken for one another. Dashes, spaces and case do not matter when it is typed.
 */
final readonly class GiftCardCode implements \Stringable
{
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const int LENGTH = 16;

    private function __construct(private string $code) {}

    public static function generate(): self
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return new self($code);
    }

    /** A code as the customer typed it; null when it cannot be one. */
    public static function typed(string $typed): ?self
    {
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $typed));

        return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $code) === 1 ? new self($code) : null;
    }

    public function hash(): string
    {
        return hash('sha256', $this->code);
    }

    public function end(): string
    {
        return substr($this->code, -4);
    }

    public function __toString(): string
    {
        return implode('-', str_split($this->code, 4));
    }
}
