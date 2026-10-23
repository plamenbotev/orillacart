<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

interface ImportRegistrationsRepository
{
    /** @return array<string, ImportRegistration> by scheme; the schemes the shop sells under */
    public function load(): array;

    /** @param array<string, ImportRegistration> $registrations */
    public function save(array $registrations): void;
}
