<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Domain;

interface ChangeRecordRepository
{
    public function save(ChangeRecord $record): void;

    /**
     * Newest first.
     *
     * @return array{list<ChangeRecord>, int} a page and how many match
     */
    public function search(?string $area, ?int $userId, int $offset, int $limit): array;

    /**
     * @return list<string> the areas changed, by name
     */
    public function areas(): array;

    /** @return int how many went */
    public function removeOlderThan(\DateTimeImmutable $before): int;
}
