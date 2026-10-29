<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Jobs\Application\Dto\NewJob;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Infrastructure\Doctrine\DbalFailedJobs;
use OrillaCart\Jobs\Infrastructure\Doctrine\DbalJobQueue;
use OrillaCart\Jobs\Infrastructure\Doctrine\JobTables;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\PluginContext;

/**
 * The queue in MySQL, as workers share it: each claims different jobs, a job pushed again while it ran runs
 * again, and a job given up waits among the failed ones until the shop retries it.
 */
final class JobQueueTest extends RestTestCase
{
    private DbalJobQueue $queue;
    private DbalFailedJobs $failed;
    private \DateTimeImmutable $now;
    private \DateTimeImmutable $lockedUntil;

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = new EntityManagerFactory(DatabaseConfig::fromWordPress(), new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->create();
        $tables = new JobTables($entityManager);
        $this->queue = new DbalJobQueue($tables);
        $this->failed = new DbalFailedJobs($tables);
        $this->now = new \DateTimeImmutable('2030-01-01 12:00:00');
        $this->lockedUntil = $this->now->modify('+5 minutes');
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testWorkersTakeDifferentJobsAndAJobPushedWhileRunningRunsAgain(): void
    {
        $this->queue->push([$this->job('1'), $this->job('2')], $this->now);

        $first = $this->queue->claim(['test'], 1, 'one', $this->now, $this->lockedUntil);
        $second = $this->queue->claim(['test'], 10, 'two', $this->now, $this->lockedUntil);
        self::assertSame(['1'], self::keys($first));
        self::assertSame(['2'], self::keys($second), 'the first one is taken');
        self::assertSame([], $this->queue->claim(['test'], 10, 'three', $this->now, $this->lockedUntil));
        self::assertSame([], $this->queue->claim(['other'], 10, 'three', $this->now->modify('+1 hour'), $this->lockedUntil), 'another lane');

        $this->queue->push([$this->job('1')], $this->now);
        $this->queue->complete($first);
        $again = $this->queue->claim(['test'], 10, 'one', $this->now, $this->lockedUntil);
        self::assertSame([['1', 2]], array_map(static fn(QueuedJob $job): array => [$job->uniqueKey, $job->version], $again), 'it changed while it ran');
        $this->queue->complete($again);
        $this->queue->reschedule($second[0], $this->now->modify('+1 day'));

        $waiting = $this->queue->waitingByQueue($this->now)['test'];
        self::assertSame([0, 0, 1], [$waiting->due, $waiting->running, $waiting->later], 'one waits for its sale');
        self::assertSame(1, $this->queue->pending('test.job'));
    }

    public function testAKeyPushedAgainTakesTheNewPayloadAndAHeldJobStaysTaken(): void
    {
        $this->queue->push([$this->job('1', ['price' => '10'])], $this->now);
        $this->queue->push([$this->job('1', ['price' => '12'])], $this->now);

        $claimed = $this->queue->claim(['test'], 10, 'one', $this->now, $this->lockedUntil);
        self::assertSame([['price' => '12']], array_map(static fn(QueuedJob $job): array => $job->payload, $claimed));

        $this->queue->hold($claimed, $this->lockedUntil->modify('+1 hour'));
        self::assertSame([], $this->queue->claim(['test'], 10, 'two', $this->lockedUntil, $this->lockedUntil->modify('+5 minutes')), 'its worker holds it longer');
    }

    public function testJobsWithoutAKeyAreEachKeptAndAStaleLockIsTakenOver(): void
    {
        $this->queue->push([$this->job(null), $this->job(null)], $this->now);
        self::assertCount(2, $this->queue->claim(['test'], 10, 'one', $this->now, $this->lockedUntil));

        $taken = $this->queue->claim(['test'], 10, 'two', $this->lockedUntil, $this->lockedUntil->modify('+5 minutes'));
        self::assertSame([2, 2], array_map(static fn(QueuedJob $job): int => $job->attempts, $taken), 'its worker died: each used an attempt');
    }

    public function testAFailedJobWaitsUntilTheShopRetriesIt(): void
    {
        $this->queue->push([$this->job('7', ['order' => 7])], $this->now);
        $job = $this->queue->claim(['test'], 10, 'one', $this->now, $this->lockedUntil)[0];
        $this->queue->retry($job, 'Timeout', $this->now->modify('+1 minute'));
        self::assertSame([], $this->queue->claim(['test'], 10, 'one', $this->now, $this->lockedUntil), 'not yet');

        $job = $this->queue->claim(['test'], 10, 'one', $this->now->modify('+1 minute'), $this->lockedUntil)[0];
        self::assertSame(2, $job->attempts);
        $this->queue->fail($job, 'Still timing out', $this->now);
        self::assertSame(0, $this->queue->pending('test.job'));

        $failed = $this->failed->list('test.job', 10);
        self::assertSame([['7', ['order' => 7], 'Still timing out', 2]], array_map(static fn($job): array => [$job->uniqueKey, $job->payload, $job->error, $job->attempts], $failed));

        self::assertSame(1, $this->failed->retry([$failed[0]->id], $this->now));
        self::assertSame(0, $this->failed->count());
        $again = $this->queue->claim(['test'], 10, 'one', $this->now, $this->lockedUntil);
        self::assertSame([[['order' => 7], 1]], array_map(static fn(QueuedJob $job): array => [$job->payload, $job->attempts], $again), 'with fresh attempts');
    }

    public function testCountsAddUpPerHourAndType(): void
    {
        $hour = $this->now->setTime(12, 0);
        $this->queue->record(['test.job' => ['done' => 3, 'retried' => 1, 'failed' => 0, 'ms' => 40]], $hour);
        $this->queue->record(['test.job' => ['done' => 2, 'retried' => 0, 'failed' => 1, 'ms' => 10]], $hour);

        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $row = $wpdb->get_row("SELECT done, retried, failed, runtime_ms FROM {$wpdb->prefix}orillacart_job_stats WHERE type = 'test.job'", ARRAY_A);
        self::assertSame(['done' => '5', 'retried' => '1', 'failed' => '1', 'runtime_ms' => '50'], $row);
    }

    public function testTheShopSeesTheLanesAndRetriesOrDeletesFailedJobs(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_failed_jobs");
        $now = new \DateTimeImmutable();
        // In a lane no handler serves, so WP-Cron's runner, which may be at work in the site meanwhile, leaves them.
        foreach (['41', '42'] as $order) {
            $this->queue->push([new NewJob('test', 'mail.order', ['order' => (int) $order, 'kind' => 'order_received'], "{$order}:order_received", $now)], $now);
            $job = $this->queue->claim(['test'], 1, 'one', $now, $now->modify('+5 minutes'))[0];
            $this->queue->fail($job, 'The mail system refused it.', $now);
        }
        $this->actAs('administrator');

        $overview = $this->data($this->request('GET', '/jobs'));
        self::assertSame(['imports', 'invoices', 'mail', 'payments', 'reports', 'search', 'shipping', 'tax', 'translations', 'webhooks'], array_column(self::list($overview['lanes']), 'queue'));
        self::assertSame(2, $overview['failed']);
        self::assertContains($overview['runner'], ['workers', 'cron', 'none']);

        $failed = $this->data($this->request('GET', '/jobs/failed?type=mail.order&per_page=1'));
        self::assertSame([2, 2], [$failed['total'], $failed['pages']]);
        $job = self::list($failed['jobs'])[0];
        self::assertIsArray($job);
        self::assertSame(['Order #42: order received email', 'The mail system refused it.', ['order' => 42, 'kind' => 'order_received']], [$job['about'], $job['error'], $job['payload']]);

        self::assertSame(422, $this->request('POST', '/jobs/failed/retry', ['ids' => ['x']], nonce: true)->get_status());
        self::assertSame(['retried' => 1], $this->data($this->request('POST', '/jobs/failed/retry', ['ids' => [$job['id']]], nonce: true)));
        self::assertSame(1, $this->queue->pending('mail.order'));
        self::assertSame(['deleted' => 1], $this->data($this->request('POST', '/jobs/failed/delete', ['all' => true], nonce: true)));
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_jobs WHERE type = 'mail.order'");
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        self::assertIsList($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function job(?string $key, array $payload = []): NewJob
    {
        return new NewJob('test', 'test.job', $payload, $key, $this->now);
    }

    /**
     * @param list<QueuedJob> $jobs
     *
     * @return list<?string>
     */
    private static function keys(array $jobs): array
    {
        return array_map(static fn(QueuedJob $job): ?string => $job->uniqueKey, $jobs);
    }

    private function clean(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        foreach (['jobs', 'failed_jobs', 'job_stats'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table} WHERE type = 'test.job'");
        }
    }
}
