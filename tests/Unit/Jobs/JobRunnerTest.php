<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Jobs;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
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
        $this->handler->outcomes['a'] = JobOutcome::retry('Timeout');

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->test->runner->work([], 10, 'test');
            $this->test->clock->sleep(60);
        }

        self::assertSame([], $this->test->queue->jobs);
        $failed = $this->test->jobs->failed('test.record');
        self::assertSame([['a', 'Timeout', 3, 'Record a']], array_map(static fn($job): array => [$job->uniqueKey, $job->error, $job->attempts, $job->about], $failed));

        unset($this->handler->outcomes['a']);
        self::assertSame(1, $this->test->jobs->retryFailed(null));
        $this->test->runAll();
        self::assertSame([], $this->test->queue->jobs);
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
