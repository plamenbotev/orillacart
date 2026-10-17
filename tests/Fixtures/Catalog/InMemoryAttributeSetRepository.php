<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Catalog;

use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\AttributeSetRepository;

final class InMemoryAttributeSetRepository implements AttributeSetRepository
{
    /** @var array<int, AttributeSet> */
    private array $sets = [];

    public function __construct(private readonly CatalogIds $ids = new CatalogIds()) {}

    public function all(): array
    {
        $sets = array_values($this->sets);
        usort($sets, static fn(AttributeSet $a, AttributeSet $b): int => [$a->name(), $a->id()] <=> [$b->name(), $b->id()]);

        return $sets;
    }

    public function find(int $id): ?AttributeSet
    {
        return $this->sets[$id] ?? null;
    }

    public function save(AttributeSet $set): void
    {
        $this->ids->set($set);
        $this->sets[$set->id()] = $set;
    }

    public function remove(AttributeSet $set): void
    {
        unset($this->sets[$set->id()]);
    }
}
