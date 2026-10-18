<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

interface PriceRuleRepository
{
    /** @return list<PriceRule> by name */
    public function all(): array;

    /** @return list<PriceRule> those switched on, whatever their dates */
    public function active(): array;

    public function find(int $id): ?PriceRule;

    public function save(PriceRule $rule): void;

    public function delete(PriceRule $rule): void;
}
