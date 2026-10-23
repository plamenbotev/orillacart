<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Tax;

use OrillaCart\Tax\Domain\ImportRegistration;
use OrillaCart\Tax\Domain\ImportRegistrationsRepository;

final class InMemoryImportRegistrations implements ImportRegistrationsRepository
{
    /** @var array<string, ImportRegistration> */
    private array $registrations = [];

    public function load(): array
    {
        return $this->registrations;
    }

    public function save(array $registrations): void
    {
        $this->registrations = $registrations;
    }
}
