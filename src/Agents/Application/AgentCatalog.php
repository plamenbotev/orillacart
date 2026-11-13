<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Application;

/** The shop's products in UCP's shape, as shoppers find them. */
interface AgentCatalog
{
    /**
     * @param list<string> $categories category names or ids, any of them
     *
     * @return array{products: list<array<string, mixed>>, pagination: array{has_next_page: bool, cursor?: string, total_count: int}}
     */
    public function search(?string $query, array $categories, ?int $priceMin, ?int $priceMax, ?string $cursor, int $limit): array;

    /**
     * @param list<string> $ids products' or variants' ids, as search gives them
     *
     * @return list<array<string, mixed>>
     */
    public function lookup(array $ids): array;
}
