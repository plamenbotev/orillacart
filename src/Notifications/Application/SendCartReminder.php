<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;

/**
 * Reminds a customer of the cart they left at checkout, in the mail lane. A mail the mail system refused is tried
 * again after a while; one sent is not sent again.
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

    public function lockSeconds(): int
    {
        return 120;
    }

    public function handle(array $jobs): array
    {
        $outcomes = [];
        foreach ($jobs as $job) {
            $id = $job->payload['abandoned'] ?? null;
            if (!\is_int($id)) {
                $outcomes[$job->id] = JobOutcome::fail('The job names no abandoned cart.');
                continue;
            }
            try {
                $this->reminders->send($id);
            } catch (EmailNotWritten $e) {
                $outcomes[$job->id] = JobOutcome::fail($e->getMessage());
            } catch (EmailNotSent $e) {
                $outcomes[$job->id] = JobOutcome::retry($e->getMessage());
            }
        }

        return $outcomes;
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Cart reminder for abandoned cart %s', \is_int($payload['abandoned'] ?? null) ? $payload['abandoned'] : '?');
    }
}
