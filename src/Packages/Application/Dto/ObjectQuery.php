<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

/**
 * `objects.ticket.query({ where: { state: "open", "order": 12 }, order_by: "-created_at", limit: 50, after: 120 })`:
 * filters on indexed fields and references, equality or a comparison (`{ "created_at >": "2026-01-01" }`), an
 * order by one indexed field or id, a page, and a cursor (the last id seen).
 */
final readonly class ObjectQuery
{
    public const int MOST = 200;

    /**
     * @param list<array{string, string, mixed}> $where field, operator (=, !=, <, <=, >, >=), value
     */
    public function __construct(
        public array $where = [],
        public string $orderBy = 'id',
        public bool $descending = false,
        public int $limit = 50,
        public ?int $after = null,
    ) {}
}
