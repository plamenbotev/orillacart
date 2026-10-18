<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;

/**
 * Gives back money that came for an order which closed unpaid and could not open again (see
 * Refunds::giveBackUnclaimed()). A job, so the provider is asked outside the transaction that settled the
 * payment, with nothing locked. A refund the provider did not answer waits on the order screen to be tried again.
 */
final readonly class GiveBackLatePayments implements JobHandler
{
    public const string TYPE = 'payment.give_back';

    public function __construct(private Refunds $refunds) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return 'payments';
    }

    public function maxAttempts(): int
    {
        return 1;
    }

    public function retryAfter(int $attempt): int
    {
        return 60;
    }

    public function handle(array $jobs): array
    {
        $outcomes = [];
        foreach ($jobs as $job) {
            $payment = $job->payload['payment'] ?? null;
            if (!\is_int($payment)) {
                $outcomes[$job->id] = JobOutcome::fail('The job does not name a payment.');
                continue;
            }
            $this->refunds->giveBackUnclaimed($payment);
        }

        return $outcomes;
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Payment #%s: give back money that came after its order closed', \is_int($payload['payment'] ?? null) ? $payload['payment'] : '?');
    }
}
