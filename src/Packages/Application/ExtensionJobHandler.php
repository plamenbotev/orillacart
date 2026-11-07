<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Packages\Domain\PackageState;

/**
 * The job that runs an extension's handler in the background: an `on <event>` handler after the event, a `job
 * "name"` handler the script pushed, an `every` schedule's turn. The payload names the extension, the method and
 * the arguments. A handler that says unavailable is tried again later; one that broke is a failure of the
 * extension and the job fails.
 */
final readonly class ExtensionJobHandler implements JobHandler
{
    public const string TYPE = 'extension.run';
    public const string QUEUE = 'extensions';

    public function __construct(private PackageService $packages, private ScriptRunner $runner) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return self::QUEUE;
    }

    public function maxAttempts(): int
    {
        return 5;
    }

    public function retryAfter(int $attempt): int
    {
        return min(3600, 60 * 4 ** ($attempt - 1));
    }

    public function lockSeconds(): int
    {
        return 120;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        $loaded = [];
        foreach ($this->packages->loaded(PackageState::Stopped) as $package) {
            $loaded[$package->package->slug()] = $package;
        }
        foreach ($jobs as $job) {
            $slug = \is_string($job->payload['extension'] ?? null) ? $job->payload['extension'] : '';
            $method = \is_string($job->payload['method'] ?? null) ? $job->payload['method'] : '';
            $arguments = \is_array($job->payload['arguments'] ?? null) ? array_values($job->payload['arguments']) : [];
            $package = $loaded[$slug] ?? null;
            if ($package === null || $method === '') {
                $progress->done($job, JobOutcome::fail(\sprintf('The extension "%s" is gone.', $slug)));
                continue;
            }
            if ($package->package->state() === PackageState::Stopped) {
                $progress->done($job, JobOutcome::fail(\sprintf('The extension "%s" is stopped.', $slug)));
                continue;
            }
            try {
                $this->runner->run($package, $method, $arguments);
                $progress->done($job);
            } catch (HandlerFailed $e) {
                $progress->done($job, $e->kind === HandlerFailed::UNAVAILABLE ? JobOutcome::retry($e->getMessage()) : JobOutcome::fail($e->getMessage()));
            }
        }

        return [];
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        $what = \is_string($payload['what'] ?? null) ? $payload['what'] : 'a handler';

        return \sprintf('Extension %s: %s', \is_string($payload['extension'] ?? null) ? $payload['extension'] : '?', $what);
    }
}
