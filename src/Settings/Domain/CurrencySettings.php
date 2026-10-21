<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * The currencies shoppers may choose besides the shop's own, which they pay in when they do.
 */
final readonly class CurrencySettings
{
    public const int MAX_CURRENCIES = 20;

    /**
     * @param list<OfferedCurrency> $offered
     */
    public function __construct(public array $offered = [])
    {
        if (\count($offered) > self::MAX_CURRENCIES) {
            throw new RuleViolation(\sprintf('A shop offers up to %d other currencies.', self::MAX_CURRENCIES), 'orillacart_too_many_currencies');
        }
        $codes = array_map(static fn(OfferedCurrency $offered): string => $offered->currency->code, $offered);
        if (\count($codes) !== \count(array_unique($codes))) {
            throw new RuleViolation('A currency is listed twice.', 'orillacart_invalid_currency');
        }
    }

    public function find(string $code): ?OfferedCurrency
    {
        return array_find($this->offered, static fn(OfferedCurrency $offered): bool => $offered->currency->code === $code);
    }

    /** The settings for a shop whose own currency is now this one: it is no longer offered besides itself. */
    public function without(string $code): self
    {
        return new self(array_values(array_filter($this->offered, static fn(OfferedCurrency $offered): bool => $offered->currency->code !== $code)));
    }
}
