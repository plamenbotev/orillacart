<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

interface SearchFilterRepository
{
    /** @return list<SearchFilter> by position, then label */
    public function all(): array;

    public function find(int $id): ?SearchFilter;

    public function isKeyTaken(string $key, ?int $exceptId = null): bool;

    public function save(SearchFilter $filter): void;

    public function remove(SearchFilter $filter): void;
}
