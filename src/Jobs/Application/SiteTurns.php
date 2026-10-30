<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * A network worker's turns on the sites, one batch each. A site whose turn throws (deleted meanwhile, its tables
 * not what the code expects, a container that cannot be built) does not stop the worker: the failure is logged on
 * that site, which is then left alone for a while, and the next site has its turn.
 */
final class SiteTurns
{
    public const int COOLDOWN_SECONDS = 60;

    /** @var array<int, int> by site id: until when (as a timestamp) the site is left alone */
    private array $skippedUntil = [];

    public function __construct(
        private readonly JobSites $sites,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * The site's turn, in its own container; null when the site is being left alone or its turn failed.
     *
     * @template T
     *
     * @param \Closure(JobRunner, JobWorkers): T $work
     *
     * @return T|null
     */
    public function on(int $site, \Closure $work): mixed
    {
        $now = $this->clock->now()->getTimestamp();
        if (($this->skippedUntil[$site] ?? 0) > $now) {
            return null;
        }
        try {
            return $this->sites->on($site, function (JobRunner $runner, JobWorkers $workers) use ($work, $site): mixed {
                try {
                    return $work($runner, $workers);
                } catch (\Throwable $e) {
                    // While the process is on the site, so the problem is kept where its shop manager reads it.
                    $this->logger->error('Site #{site}: its turn at the jobs failed and the site is left alone for {seconds} seconds: {message}', ['site' => $site, 'seconds' => self::COOLDOWN_SECONDS, 'message' => $e->getMessage(), 'exception' => $e]);
                    throw new SiteTurnFailed($e);
                }
            });
        } catch (SiteTurnFailed) {
            $this->skippedUntil[$site] = $now + self::COOLDOWN_SECONDS;

            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Site #{site} could not be served and is left alone for {seconds} seconds: {message}', ['site' => $site, 'seconds' => self::COOLDOWN_SECONDS, 'message' => $e->getMessage(), 'exception' => $e]);
            $this->skippedUntil[$site] = $now + self::COOLDOWN_SECONDS;

            return null;
        }
    }

    /** Whether the site is being left alone after a failed turn. */
    public function skipped(int $site): bool
    {
        return ($this->skippedUntil[$site] ?? 0) > $this->clock->now()->getTimestamp();
    }
}
