<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Storefront;

use OrillaCart\Storefront\Domain\OpeningRepository;

final class InMemoryOpening implements OpeningRepository
{
    public function __construct(public bool $opensSoon = false) {}

    public function opensSoon(): bool
    {
        return $this->opensSoon;
    }

    public function save(bool $opensSoon): void
    {
        $this->opensSoon = $opensSoon;
    }
}
