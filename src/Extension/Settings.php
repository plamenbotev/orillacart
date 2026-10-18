<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

/**
 * The values the shop saved for a gateway's or shipping method's settings.
 */
final readonly class Settings
{
    /**
     * @param array<string, string|bool> $values by key
     */
    public function __construct(private array $values = []) {}

    /** A text, secret, number or choice; empty when not set. */
    public function text(string $key): string
    {
        $value = $this->values[$key] ?? '';

        return \is_string($value) ? $value : '';
    }

    public function flag(string $key): bool
    {
        return ($this->values[$key] ?? false) === true;
    }

    /** @return array<string, string|bool> */
    public function all(): array
    {
        return $this->values;
    }
}
