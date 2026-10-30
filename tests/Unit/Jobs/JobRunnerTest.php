<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Jobs;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Tests\Fixtures\Jobs\RecordingHandler;
use OrillaCart\Tests\Fixtures\Jobs\TestJobs;
use PHPUnit\Framework\TestCase;

final class JobRunnerTest extends TestCase
{
    private TestJobs $test;
    private RecordingHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new RecordingHandler();
        $this->test = new TestJobs()->with($this->handler);
    }

    public function testJobsOfATypeRunAsOneBatchAndLeaveTheQueue(): void
    {
        $this->push('a', 'b', 'c');

        $run = $this->test->runner->work([], 10, 'test');

        self::assertSame(3, $run->done);
        self::assertSame([['a', 'b', 'c']], $this->handler->batches);
        self::assertSame([], $this->test->queue->jobs);
        self::assertSame(3, array_values($this->test->queue->stats)[0]['test.record']['done'], 'counted for the hour');
    }

    public function testPushingAKeyAgainBeforeItRunsKeepsOneJob(): void
    {
        $this->push('a', 'a', 'a');

        self::assertCount(1, $this->test->queue->jobs);
    }

    public function testPushingAKeyAgainGivesTheWaitingJobTheNewPayload(): void
    {
        $this->test->jobs->push(RecordingHandler::type(), ['price' => '10'], 'a');
        $this->test->jobs->push(RecordingHandler::type(), ['price' => '12'], 'a');

        self::assertSame([['price' => '12']], array_column(array_values($this->test->queue->jobs), 'payload'));
    }

    public function testJobsRunAsNobodyInTheSiteLanguageAndAreHeldAsLongAsTheirHandlerSays(): void
    {
        $this->handler->lock = 1800;
        $held = [];
        $this->handler->during = function (array $jobs) use (&$held): void {
            foreach ($jobs as $job) {
                if ($job instanceof QueuedJob) {
                    $held[] = $this->test->queue->jobs[(int) $job->id]['lockedUntil'];
                }
            }
        };
        $this->push('a');

        $this->test->runner->work([], 10, 'test');

        self::assertSame(1, $this->test->context->runs);
        self::assertEquals([$this->test->clock->now()->modify('+1800 seconds')], $held);
    }

    public function testAJobReportedDoneIsSettledBeforeTheNextRunsAndTheRestAreHeldOn(): void
    {
        $this->handler->lock = 60;
        $this->handler->reports = true;
        $this->push('a', 'b', 'c');
        $seen = [];
        $this->handler->before = function (QueuedJob $job) use (&$seen): void {
            // Each job takes longer than the lock: the one before it is gone from the queue, the rest are held on.
            $seen[(string) $job->uniqueKey] = [array_column($this->test->queue->jobs, 'key'), $this->test->queue->jobs[(int) $job->id]['lockedUntil']];
            $this->test->clock->sleep(90);
        };

        $run = $this->test->runner->work([], 10, 'test');

        self::assertSame(3, $run->done);
        $start = new \DateTimeImmutable('2030-01-01 12:00:00');
        self::assertEquals([
            'a' => [['a', 'b', 'c'], $start->modify('+60 seconds')],
            'b' => [['b', 'c'], $start->modify('+90 seconds +60 seconds')],
            'c' => [['c'], $start->modify('+180 seconds +60 seconds')],
        ], $seen);
        self::assertSame([], $this->test->queue->jobs);
    }

    public function testAHandlerThatReportsSomeJobsAndThrowsHasOnlyTheRestRunAgain(): void
    {
        $this->handler->reports = true;
        $this->push('a', 'b', 'c');
        $this->handler->outcomes['b'] = new \RuntimeException('B is broken.');

        $run = $this->test->runner->work([], 10, 'test');

        self::assertSame([2, 1, 0], [$run->done, $run->retried, $run->failed]);
        self::assertSame([['a', 'b', 'c'], ['b'], ['c']], $this->handler->batches, 'a was done before the batch failed');
    }

    public function testTheTypesWaitingTheirTurnStayHeldWhileAnEarlierTypeRunsLong(): void
    {
        $other = new class implements JobHandler {
            /** @var list<list<?string>> */
            public array $batches = [];

            public static function type(): string
            {
                return 'test.other';
            }

            public static function queue(): string
            {
                return 'test';
            }

            public function maxAttempts(): int
            {
                return 3;
            }

            public function retryAfter(int $attempt): int
            {
                return 10;
            }

            public function lockSeconds(): int
            {
                return 60;
            }

            public function handle(array $jobs, JobProgress $progress): array
            {
                $this->batches[] = array_map(static fn(QueuedJob $job): ?string => $job->uniqueKey, $jobs);

                return [];
            }

            public function describe(array $payload, ?string $uniqueKey): string
            {
                return 'Other';
            }
        };
        $this->test->with($other);
        $this->handler->reports = true;
        $this->push('a', 'b');
        $this->test->jobs->push($other::type(), [], 'z');
        $this->handler->before = fn() => $this->test->clock->sleep(200);

        $this->test->runner->work([], 10, 'test');

        self::assertSame([['z']], $other->batches);
        self::assertSame([], $this->test->queue->jobs);
        // While a and b ran for 400 seconds, z's claim of 300 was renewed when it was about to run out.
        $held = array_values(array_filter($this->test->queue->held, static fn(array $hold): bool => $hold[0] === ['z']));
        self::assertEquals([['z'], new \DateTimeImmutable('2030-01-01 12:00:00 +200 seconds +300 seconds')], $held[0] ?? null, 'held for another 300 seconds once less than half of the claim was left');
    }

    public function testWithoutAServerThatAnswersFirstARequestLeavesItsJobsToCron(): void
    {
        $this->test->detaches = false;
        $this->push('a');
        $this->test->endRequest();

        self::assertSame([], $this->handler->batches);
        self::assertCount(1, $this->test->queue->jobs);
    }

    public function testOneBrokenJobIsRetriedAloneAfterItsWaitAndTheRestAreDone(): void
    {
        $this->push('a', 'b', 'c');
        $this->handler->outcomes['b'] = new \RuntimeException('B is broken.');

        $run = $this->test->runner->work([], 10, 'test');

        self::assertSame([2, 1, 0], [$run->done, $run->retried, $run->failed]);
        self::assertSame([['a', 'b', 'c'], ['a'], ['b'], ['c']], $this->handler->batches, 'the batch again one by one');
        $left = array_values($this->test->queue->jobs);
        self::assertSame(['b', 'B is broken.'], [$left[0]['key'], $left[0]['error']]);
        self::assertEquals($this->test->clock->now()->modify('+10 seconds'), $left[0]['at']);
        self::assertSame(['warning', 'error'], array_column($this->test->logger->records, 'level'), 'the batch failing, then the job');
    }

    public function testAJobThatKeepsFailingIsGivenUpForTheShopToRetry(): void
    {
        $this->push('a');
        $this->handler->outcomes['a'] = JobOutcome::retry('Timeout sending to ada.lovelace+shop@example.co.uk');

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->test->runner->work([], 10, 'test');
            $this->test->clock->sleep(60);
        }

        self::assertSame([], $this->test->queue->jobs);
        $failed = $this->test->jobs->failed('test.record');
        self::assertSame([['a', 'Timeout sending to [email]', 3, 'Record a']], array_map(static fn($job): array => [$job->uniqueKey, $job->error, $job->attempts, $job->about], $failed));

        unset($this->handler->outcomes['a']);
        self::assertSame(1, $this->test->jobs->retryFailed(null));
        $this->test->runAll();
        self::assertSame([], $this->test->queue->jobs);
        self::assertSame(0, $this->test->queue->failed->count());
    }

    public function testAFailureIsForgottenOnceTheSameWorkIsDone(): void
    {
        $this->push('a');
        $this->handler->outcomes['a'] = JobOutcome::fail('Broken');
        $this->test->runner->work([], 10, 'test');
        self::assertSame(1, $this->test->queue->failed->count());

        // Pushed again (the order changed, or a rebuild), and done this time: nothing is waiting any more.
        unset($this->handler->outcomes['a']);
        $this->push('a');
        $this->test->runAll();
        self::assertSame(0, $this->test->queue->failed->count());
    }

    public function testAJobWhoseWorkerDiedEachTimeIsGivenUp(): void
    {
        $this->push('a');
        $later = $this->test->clock->now()->modify('+1 hour');
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            // Claimed, then never finished: the lock runs out.
            $this->test->queue->claim([], 10, 'dead', $this->test->clock->now(), $this->test->clock->now()->modify('+5 minutes'));
            $this->test->clock->sleep(301);
        }
        self::assertLessThan($later, $this->test->clock->now());

        $run = $this->test->runner->work([], 10, 'test');

        self::assertSame(1, $run->failed);
        self::assertSame([], $this->handler->batches, 'not run a fourth time');
        self::assertStringContainsString('worker stopped', $this->test->queue->failed->list(null, 1)[0]->error);
    }

    public function testAJobPushedAgainWhileRunningRunsAgain(): void
    {
        $this->push('a');
        $this->handler->outcomes['a'] = JobOutcome::done();
        $claimed = $this->test->queue->claim([], 10, 'one', $this->test->clock->now(), $this->test->clock->now()->modify('+5 minutes'));
        $this->push('a');
        $this->test->queue->complete($claimed);

        self::assertCount(1, $this->test->queue->jobs);
        $this->test->runAll();
        self::assertSame([], $this->test->queue->jobs);
    }

    public function testARescheduledJobWaitsUntilItsTime(): void
    {
        $this->push('a');
        $this->handler->outcomes['a'] = JobOutcome::reschedule($this->test->clock->now()->modify('+1 day'));

        $this->test->runAll();

        $waiting = $this->test->jobs->waiting('test.record');
        self::assertSame([0, 1], [$waiting->due, $waiting->later]);
    }

    public function testAJobOfAnUnknownTypeFails(): void
    {
        $this->test->queue->push([new \OrillaCart\Jobs\Application\Dto\NewJob('test', 'test.gone', [], null, $this->test->clock->now())], $this->test->clock->now());

        $this->test->runAll();

        self::assertStringContainsString('No handler', $this->test->queue->failed->list(null, 1)[0]->error);
    }

    public function testARequestRunsItsJobsAfterTheResponseUnlessAWorkerServesTheirLane(): void
    {
        $this->push('a');
        $this->test->endRequest();
        self::assertSame([['a']], $this->handler->batches);

        $this->test->workers->heartbeat('worker', ['test'], $this->test->clock->now(), null, 0, 0);
        $this->push('b');
        $this->test->endRequest();
        self::assertSame([['a']], $this->handler->batches, 'the worker takes it');

        $this->test->workers->heartbeat('worker', ['mail'], $this->test->clock->now(), null, 0, 0);
        $this->test->endRequest();
        $this->push('c');
        $this->test->endRequest();
        self::assertSame([['a'], ['b', 'c']], $this->handler->batches, 'the worker serves another lane');
    }

    public function testWorkersRestartWhenAskedAfterTheyStarted(): void
    {
        $started = $this->test->clock->now();
        self::assertFalse($this->test->workers->shouldRestart($started));
        $this->test->workers->restart();
        self::assertTrue($this->test->workers->shouldRestart($started));
        $this->test->clock->sleep(1);
        self::assertFalse($this->test->workers->shouldRestart($this->test->clock->now()), 'a worker started since');
    }

    private function push(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->test->jobs->push(RecordingHandler::type(), ['key' => $key], $key);
        }
    }
}
