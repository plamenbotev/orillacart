<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Payment;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Payment\Application\GiveBackLatePayments;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Jobs\RecordedProgress;
use PHPUnit\Framework\TestCase;

/**
 * The job that gives back money which came too late: each payment is settled as its provider was asked.
 */
final class GiveBackLatePaymentsTest extends TestCase
{
    public function testEachPaymentIsReportedAsItIsDoneAndOneWithoutAPaymentIsFailedAlone(): void
    {
        $shop = new TestShop();
        $handler = $shop->jobs->get(GiveBackLatePayments::TYPE);

        $progress = new RecordedProgress();
        self::assertSame([], $handler->handle([self::job('first', ['payment' => 404]), self::job('second', []), self::job('third', ['payment' => 405])], $progress->progress));

        self::assertSame(['first' => 'done', 'second' => 'fail', 'third' => 'done'], array_map(static fn(JobOutcome $outcome): string => $outcome->kind, $progress->outcomes), 'a payment gone since is nothing to give back; a job naming none is failed on its own');
        self::assertSame('The job does not name a payment.', $progress->outcomes['second']->error);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function job(string $id, array $payload): QueuedJob
    {
        return new QueuedJob($id, 'payments', GiveBackLatePayments::TYPE, null, $payload, 1, 1);
    }
}
