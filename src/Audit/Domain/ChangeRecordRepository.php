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

    /**
     * Replaces what was sent with $values in the records made on any of $paths or below them, and in those whose
     * values mention $mention (any case).
     *
     * @param list<string>         $paths  "/orders/12": that route and those below it
     * @param array<string, mixed> $values
     *
     * @return int how many were changed
     */
    public function replaceValues(array $paths, ?string $mention, array $values): int;

    /** @return int how many went */
    public function removeOlderThan(\DateTimeImmutable $before): int;
}
