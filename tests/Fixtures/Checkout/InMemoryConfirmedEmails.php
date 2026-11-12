<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Checkout;

use OrillaCart\Checkout\Application\ConfirmedEmails;

final class InMemoryConfirmedEmails implements ConfirmedEmails
{
    /** @var array<string, int> */
    public array $kept = [];

    public function keep(string $key, int $until): void
    {
        $this->kept[$key] = $until;
    }

    public function until(string $key): ?int
    {
        return $this->kept[$key] ?? null;
    }
}
