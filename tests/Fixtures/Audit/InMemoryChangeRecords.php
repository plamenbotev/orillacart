<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Audit;

use OrillaCart\Audit\Domain\ChangeRecord;
use OrillaCart\Audit\Domain\ChangeRecordRepository;

final class InMemoryChangeRecords implements ChangeRecordRepository
{
    /** @var list<ChangeRecord> */
    public array $records = [];

    public function save(ChangeRecord $record): void
    {
        new \ReflectionProperty(ChangeRecord::class, 'id')->setValue($record, \count($this->records) + 1);
        $this->records[] = $record;
    }

    public function search(?string $area, ?int $userId, int $offset, int $limit): array
    {
        return [\array_slice(array_reverse($this->records), $offset, $limit), \count($this->records)];
    }

    public function areas(): array
    {
        return array_values(array_unique(array_map(static fn(ChangeRecord $record): string => $record->area(), $this->records)));
    }

    public function replaceValues(array $paths, ?string $mention, array $values): int
    {
        $changed = 0;
        foreach ($this->records as $record) {
            $below = array_filter($paths, static fn(string $path): bool => $record->path() === $path || str_starts_with($record->path(), "{$path}/"));
            $mentions = $mention !== null && ChangeRecord::mentions($record->values(), $mention);
            if ($below !== [] || $mentions) {
                new \ReflectionProperty(ChangeRecord::class, 'values')->setValue($record, $values);
                ++$changed;
            }
        }

        return $changed;
    }

    public function removeOlderThan(\DateTimeImmutable $before): int
    {
        return 0;
    }
}
