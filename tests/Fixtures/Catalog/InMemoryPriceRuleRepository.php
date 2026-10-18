<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Catalog;

use OrillaCart\Catalog\Domain\PriceRule;
use OrillaCart\Catalog\Domain\PriceRuleRepository;

final class InMemoryPriceRuleRepository implements PriceRuleRepository
{
    /** @var array<int, PriceRule> */
    public array $rules = [];
    private int $nextId = 1;

    public function all(): array
    {
        $rules = array_values($this->rules);
        usort($rules, static fn(PriceRule $a, PriceRule $b): int => $a->name() <=> $b->name());

        return $rules;
    }

    public function active(): array
    {
        return array_values(array_filter($this->rules, static fn(PriceRule $rule): bool => $rule->isActive()));
    }

    public function find(int $id): ?PriceRule
    {
        return $this->rules[$id] ?? null;
    }

    public function save(PriceRule $rule): void
    {
        $id = new \ReflectionProperty(PriceRule::class, 'id');
        if ($id->getValue($rule) === null) {
            $id->setValue($rule, $this->nextId++);
        }
        $this->rules[$rule->id()] = $rule;
    }

    public function delete(PriceRule $rule): void
    {
        unset($this->rules[$rule->id()]);
    }
}
