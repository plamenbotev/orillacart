<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Jobs;

use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Jobs\Application\JobSites;
use OrillaCart\Jobs\Application\JobWorkers;
use OrillaCart\Jobs\Application\SiteTurns;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use OrillaCart\Tests\Fixtures\Jobs\TestJobs;
use PHPUnit\Framework\TestCase;

final class SiteTurnsTest extends TestCase
{
    public function testASiteWhoseTurnFailsIsLoggedAndLeftAloneForAWhileAndTheOthersGoOn(): void
    {
        $jobs = new TestJobs();
        $logger = new MemoryLogger();
        $served = [];
        $sites = new class ($jobs, $served) implements JobSites {
            /** @param list<int> $served */
            public function __construct(private readonly TestJobs $jobs, public array &$served) {}

            public function network(): bool
            {
                return true;
            }

            public function sites(): array
            {
                return [1, 2, 3];
            }

            public function chores(): void {}

            public function on(int $site, \Closure $work): mixed
            {
                if ($site === 3) {
                    throw new \RuntimeException('Site 3 was deleted.');
                }
                $this->served[] = $site;

                return $work($this->jobs->runner, $this->jobs->workers);
            }
        };
        $turns = new SiteTurns($sites, $logger, $jobs->clock);
        $ok = static fn(JobRunner $runner, JobWorkers $workers): string => 'served';
        $bad = static fn(JobRunner $runner, JobWorkers $workers): string => throw new \LogicException('Its tables are behind.');

        self::assertSame('served', $turns->on(1, $ok));
        self::assertNull($turns->on(2, $bad), 'the batch threw on the site');
        self::assertNull($turns->on(3, $bad), 'the site could not be served at all');
        self::assertNull($turns->on(2, $ok), 'left alone');
        self::assertSame('served', $turns->on(1, $ok), 'the others go on');
        self::assertSame([1, 2, 1], $sites->served);
        self::assertSame(['error', 'error'], array_column($logger->records, 'level'));
        self::assertSame(['Its tables are behind.', 'Site 3 was deleted.'], array_column(array_column($logger->records, 'context'), 'message'));

        $jobs->clock->sleep(SiteTurns::COOLDOWN_SECONDS);
        self::assertFalse($turns->skipped(2));
        self::assertSame('served', $turns->on(2, $ok), 'tried again after the cooldown');
    }
}
