<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * An ISO 4217 currency code such as EUR or USD.
 */
final readonly class Currency implements \Stringable
{
    public string $code;

    public function __construct(string $code)
    {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new RuleViolation(\sprintf('"%s" is not a currency code; use three capital letters such as EUR.', $code), 'orillacart_invalid_currency');
        }
        $this->code = $code;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
