<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Tax;

use OrillaCart\Tax\Domain\TaxExemption;
use OrillaCart\Tax\Domain\TaxExemptionRepository;

final class InMemoryTaxExemptionRepository implements TaxExemptionRepository
{
    /** @var array<int, TaxExemption> */
    private array $exemptions = [];
    private int $nextId = 1;

    public function find(int $id): ?TaxExemption
    {
        return $this->exemptions[$id] ?? null;
    }

    public function ofCustomer(int $customerId): array
    {
        $found = array_values(array_filter($this->exemptions, static fn(TaxExemption $exemption): bool => $exemption->customerId() === $customerId));
        usort($found, static fn(TaxExemption $a, TaxExemption $b): int => $b->id() <=> $a->id());

        return $found;
    }

    public function save(TaxExemption $exemption): void
    {
        $id = new \ReflectionProperty(TaxExemption::class, 'id');
        if ($id->getValue($exemption) === null) {
            $id->setValue($exemption, $this->nextId++);
        }
        $this->exemptions[$exemption->id()] = $exemption;
    }

    public function remove(TaxExemption $exemption): void
    {
        unset($this->exemptions[$exemption->id()]);
    }

    public function removeOfCustomer(int $customerId): void
    {
        foreach ($this->ofCustomer($customerId) as $exemption) {
            $this->remove($exemption);
        }
    }
}
