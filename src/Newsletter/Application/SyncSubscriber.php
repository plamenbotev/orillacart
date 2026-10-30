<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Application;

use OrillaCart\Extension\Marketing\ForgetsSubscribers;
use OrillaCart\Extension\Marketing\Subscriber as ListSubscriber;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use Psr\Log\LoggerInterface;

/**
 * Tells a mailing list how an email stands at the shop now: subscribed, or not (unsubscribed, or erased). A list
 * that cannot be reached is asked again later; a list the shop no longer sends to is left alone.
 */
final readonly class SyncSubscriber implements JobHandler
{
    public const string TYPE = 'newsletter.sync';

    public function __construct(
        private Newsletter $newsletter,
        private LoggerInterface $logger,
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
        return 6;
    }

    public function retryAfter(int $attempt): int
    {
        return 60 * 4 ** ($attempt - 1);
    }

    public function lockSeconds(): int
    {
        return 60;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        $outcomes = [];
        foreach ($jobs as $job) {
            $email = $job->payload['email'] ?? null;
            $forget = ($job->payload['forget'] ?? false) === true;
            $list = \is_string($job->payload['list'] ?? null) ? $this->newsletter->list($job->payload['list'], any: $forget) : null;
            if (!\is_string($email) || $list === null) {
                continue;
            }
            $subscriber = $forget ? null : $this->newsletter->standing($email);
            try {
                if ($forget && $list instanceof ForgetsSubscribers) {
                    $list->forget($email);
                } elseif ($subscriber === null) {
                    $list->unsubscribe($email);
                } else {
                    $list->subscribe(new ListSubscriber($subscriber->email(), $subscriber->firstName(), $subscriber->lastName(), $subscriber->consent(), $subscriber->subscribedAt(), $subscriber->source()->value));
                }
            } catch (ProviderUnavailable $e) {
                $outcomes[$job->id] = JobOutcome::retry($e->getMessage());
            } catch (\Throwable $e) {
                $this->logger->error('The mailing list {list} failed: {message}', ['list' => $list->id(), 'message' => $e->getMessage(), 'exception' => $e]);
                $outcomes[$job->id] = JobOutcome::fail(\sprintf('The mailing list %s failed: %s', $list->id(), $e->getMessage()));
            }
        }

        return $outcomes;
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Tell the mailing list %s about %s', \is_string($payload['list'] ?? null) ? $payload['list'] : '?', \is_string($payload['email'] ?? null) ? $payload['email'] : '?');
    }
}
