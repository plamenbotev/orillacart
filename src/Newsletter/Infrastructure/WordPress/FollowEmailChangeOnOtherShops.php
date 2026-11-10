<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\WordPress;

use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Shared\WordPress\OtherShops;
use Psr\Container\ContainerInterface;

/**
 * A network user's email changed: their newsletter sign-up follows it on the network's other shops, in the
 * background. Each shop is run as its site, so doing this in the request that changed the email would build every
 * shop's container there, and bring up those behind an update.
 */
final readonly class FollowEmailChangeOnOtherShops implements JobHandler
{
    public const string TYPE = 'newsletter.follow_email';

    public function __construct(private OtherShops $shops) {}

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
        return 3;
    }

    public function retryAfter(int $attempt): int
    {
        return 300 * $attempt;
    }

    /** Every shop of the network, one after another. */
    public function lockSeconds(): int
    {
        return 600;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        foreach ($jobs as $job) {
            $from = $job->payload['from'] ?? null;
            $to = $job->payload['to'] ?? null;
            if (\is_string($from) && \is_string($to)) {
                $this->shops->each(static function (ContainerInterface $shop) use ($from, $to): void {
                    $newsletter = $shop->get(Newsletter::class);
                    \assert($newsletter instanceof Newsletter);
                    $newsletter->changeEmail($from, $to);
                });
            }
            $progress->done($job, null);
        }

        return [];
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return 'Newsletter sign-up following a changed email on the network\'s other shops';
    }
}
