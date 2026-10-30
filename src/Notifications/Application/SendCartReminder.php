<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;

/**
 * Reminds a customer of the cart they left at checkout, in the mail lane. A mail the mail system refused is tried
 * again after a while; one sent is not sent again. Each reminder is reported as it is sent.
 */
final readonly class SendCartReminder implements JobHandler
{
    public const string TYPE = 'mail.cart_reminder';

    public function __construct(private CartReminders $reminders) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return 'mail';
    }

    public function maxAttempts(): int
    {
        return 5;
    }

    public function retryAfter(int $attempt): int
    {
        return 60 * 4 ** ($attempt - 1);
    }

    /** One mail, with a slow mail server. */
    public function lockSeconds(): int
    {
        return 120;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        // Each reminder is settled as it is sent, so a worker killed mid-batch leaves one to do again.
        foreach ($jobs as $job) {
            $progress->done($job, $this->remind($job));
        }

        return [];
    }

    /** One job's reminder. */
    private function remind(QueuedJob $job): JobOutcome
    {
        $id = $job->payload['abandoned'] ?? null;
        if (!\is_int($id)) {
            return JobOutcome::fail('The job names no abandoned cart.');
        }
        try {
            $this->reminders->send($id);
        } catch (EmailNotWritten $e) {
            return JobOutcome::fail($e->getMessage());
        } catch (EmailNotSent $e) {
            return JobOutcome::retry($e->getMessage());
        }

        return JobOutcome::done();
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Cart reminder for abandoned cart %s', \is_int($payload['abandoned'] ?? null) ? $payload['abandoned'] : '?');
    }
}
