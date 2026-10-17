<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Domain;

interface CountryRepository
{
    /**
     * @return list<Country> by name, states loaded
     */
    public function all(): array;

    public function find(string $code): ?Country;
}
