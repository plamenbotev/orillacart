<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

interface AttributeSetRepository
{
    /**
     * @return list<AttributeSet> by name
     */
    public function all(): array;

    public function find(int $id): ?AttributeSet;

    /**
     * Saves the set with its attributes and options; new ones have their ids afterwards.
     */
    public function save(AttributeSet $set): void;

    public function remove(AttributeSet $set): void;
}
