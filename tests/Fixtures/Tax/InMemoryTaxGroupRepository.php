<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Tax;

use OrillaCart\Tax\Domain\TaxGroup;
use OrillaCart\Tax\Domain\TaxGroupRepository;
use OrillaCart\Tax\Domain\TaxRate;

/**
 * Keeps groups in memory and numbers groups and rates on save, as the database does.
 */
final class InMemoryTaxGroupRepository implements TaxGroupRepository
{
    /** @var array<int, TaxGroup> */
    private array $groups = [];
    private int $nextGroupId = 1;
    private int $nextRateId = 1;

    public function all(): array
    {
        $groups = array_values($this->groups);
        usort($groups, static fn(TaxGroup $a, TaxGroup $b): int => [$a->name(), $a->id()] <=> [$b->name(), $b->id()]);

        return $groups;
    }

    public function find(int $id): ?TaxGroup
    {
        return $this->groups[$id] ?? null;
    }

    public function save(TaxGroup $group): void
    {
        $id = new \ReflectionProperty(TaxGroup::class, 'id');
        if ($id->getValue($group) === null) {
            $id->setValue($group, $this->nextGroupId++);
        }
        $rateId = new \ReflectionProperty(TaxRate::class, 'id');
        foreach ($group->rates() as $rate) {
            if (!$rate->isSaved()) {
                $rateId->setValue($rate, $this->nextRateId++);
            }
        }
        $this->groups[$group->id()] = $group;
    }

    public function remove(TaxGroup $group): void
    {
        unset($this->groups[$group->id()]);
    }
}
