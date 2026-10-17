<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

/**
 * A country ("BG") or one state of a country ("US-CA") that a rate ships to.
 */
final readonly class Region implements \Stringable
{
    private function __construct(
        public string $country,
        public ?string $state,
    ) {}

    public static function of(string $code): self
    {
        if (preg_match('/^([A-Z]{2})(?:-([A-Z0-9]{1,3}))?$/', $code, $parts) !== 1) {
            throw new ShippingRuleViolation(\sprintf('"%s" is not a region; use a country code such as "BG" or a state such as "US-CA".', $code), 'orillacart_invalid_region');
        }

        return new self($parts[1], $parts[2] ?? null);
    }

    public function contains(Destination $destination): bool
    {
        return $destination->country === $this->country && ($this->state === null || $destination->state === $this->state);
    }

    public function __toString(): string
    {
        return $this->state === null ? $this->country : "{$this->country}-{$this->state}";
    }
}
