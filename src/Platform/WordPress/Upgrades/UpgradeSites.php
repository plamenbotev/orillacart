<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Upgrades;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\KeepsFailures;
use Psr\Clock\ClockInterface;

/**
 * Upgrades one site of the network after an update, from the coordinating site's queue (see SiteUpgrades). A site
 * its own visitor upgraded first is done at once. A failed job stays until it is retried: the site is behind until
 * it runs.
 */
final readonly class UpgradeSites implements KeepsFailures
{
    public const string TYPE = 'platform.upgrade_site';
    /** How long a batch goes on before it leaves its other sites for the next run. */
    private const int BATCH_SECONDS = 60;

    public function __construct(
        private SiteUpgrades $upgrades,
        private ClockInterface $clock,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return 'upgrades';
    }

    public function maxAttempts(): int
    {
        return 5;
    }

    public function retryAfter(int $attempt): int
    {
        return 60 * 2 ** ($attempt - 1);
    }

    public function lockSeconds(): int
    {
        // A batch stops starting sites after a minute; a site's migrations may take long on big tables.
        return 1800;
    }

    public function handle(array $jobs): array
    {
        $started = microtime(true);
        $outcomes = [];
        foreach ($jobs as $job) {
            if (microtime(true) - $started > self::BATCH_SECONDS) {
                $outcomes[$job->id] = JobOutcome::reschedule($this->clock->now());
                continue;
            }
            $site = $job->payload['site'] ?? null;
            if (!\is_int($site)) {
                $outcomes[$job->id] = JobOutcome::fail('The job does not name a site.');
                continue;
            }
            $outcome = $this->upgrades->upgradeSite($site, walk: false);
            if ($outcome->isBusy()) {
                $outcomes[$job->id] = JobOutcome::retry('Another request was upgrading the site\'s database.');
            } elseif ($outcome->isFailed()) {
                $outcomes[$job->id] = JobOutcome::retry($outcome->error);
            }
        }

        return $outcomes;
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        $site = $payload['site'] ?? null;
        if (!\is_int($site)) {
            return 'A site of the network: upgrade its database';
        }

        return \sprintf('Site #%d (%s): upgrade its database to %s', $site, get_home_url($site), \is_string($payload['build'] ?? null) ? $payload['build'] : '?');
    }
}
