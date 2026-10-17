<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

interface TaxGroupRepository
{
    /**
     * @return list<TaxGroup> by name
     */
    public function all(): array;

    public function find(int $id): ?TaxGroup;

    /**
     * Saves the group with its rates; a new group has its id afterwards.
     */
    public function save(TaxGroup $group): void;

    public function remove(TaxGroup $group): void;
}
