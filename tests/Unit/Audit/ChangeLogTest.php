<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Audit;

use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Audit\Application\Dto\ChangeLogQuery;
use OrillaCart\Audit\Domain\ChangeRecord;
use OrillaCart\Audit\Domain\ChangeRecordRepository;
use OrillaCart\Shared\Users\UserNames;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ChangeLogTest extends TestCase
{
    public function testSecretsAreBlankedOutAndTheAreaIsTheRoutesFirstPart(): void
    {
        $records = new InMemoryChangeRecords();
        $log = new ChangeLog($records, new class implements UserNames {
            public function names(array $ids): array
            {
                return [1 => 'Ada'];
            }
        }, new MockClock('2026-10-21 08:00:00 UTC'));

        $log->record(1, 'PUT', '/payment-methods/{gateway}', '/payment-methods/paypal', [
            'name' => 'PayPal',
            'settings' => ['client_id' => 'abc', 'client_secret' => 's3cret', 'webhook_id' => '', 'api_key' => null],
            'password' => 'hunter2',
            'key' => 'k',
        ]);

        $view = $log->page(new ChangeLogQuery())->records[0];
        self::assertSame(['Ada', 'payment-methods', 'PUT /payment-methods/{gateway}'], [$view->user, $view->area, $view->route]);
        self::assertSame(['name' => 'PayPal', 'settings' => ['client_id' => 'abc', 'client_secret' => '••••', 'webhook_id' => '', 'api_key' => null], 'password' => '••••', 'key' => '••••'], $view->values, 'an empty secret says it was emptied');
    }

    public function testWhatIsTooLongIsNotKeptButTheChangeIs(): void
    {
        $records = new InMemoryChangeRecords();
        new ChangeLog($records, new class implements UserNames {
            public function names(array $ids): array
            {
                return [];
            }
        }, new MockClock())->record(null, 'POST', '/products/import', '/products/import', ['csv' => str_repeat('x', 10000)]);

        self::assertSame(['_' => 'Too long to keep.'], $records->records[0]->values());
        self::assertSame('', $records->records[0]->userName());
    }
}

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

    public function removeOlderThan(\DateTimeImmutable $before): int
    {
        return 0;
    }
}
