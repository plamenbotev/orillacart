<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;
use Psr\Clock\ClockInterface;

/**
 * Writes to those waiting for an item that is back in stock, in the mail lane, a batch at a time. A mail the
 * mail system refused is tried again after a while; the ones already sent are forgotten, and not sent twice.
 */
final readonly class SendBackInStock implements JobHandler
{
    public const string TYPE = 'mail.back_in_stock';

    public function __construct(
        private BackInStock $backInStock,
        private ClockInterface $clock,
    ) {}

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
        return 600;
    }

    public function handle(array $jobs): array
    {
        $outcomes = [];
        foreach ($jobs as $job) {
            $item = $job->payload['item'] ?? null;
            if (!\is_string($item)) {
                $outcomes[$job->id] = JobOutcome::fail('The job names no item.');
                continue;
            }
            try {
                if ($this->backInStock->send($item)) {
                    $outcomes[$job->id] = JobOutcome::reschedule($this->clock->now());
                }
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
        return \sprintf('Back in stock emails for %s', \is_string($payload['item'] ?? null) ? $payload['item'] : '?');
    }
}
