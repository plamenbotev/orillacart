<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\Jobs;

/**
 * Registers every object with the translation service, or takes back what its translators finished, in the
 * background: a shop's whole catalog of words is more than a request should wait for. Queued once however often
 * asked before it runs.
 */
final readonly class SyncTranslationPackages implements JobHandler
{
    public const string TYPE = 'translations.packages';
    public const string REGISTER = 'register';
    public const string PULL = 'pull';

    public function __construct(
        private TranslationPackages $packages,
        private Jobs $jobs,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return 'translations';
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function retryAfter(int $attempt): int
    {
        return 120 * $attempt;
    }

    public function lockSeconds(): int
    {
        return 600;
    }

    public function registerAll(): void
    {
        if ($this->packages->active()) {
            $this->jobs->push(self::TYPE, [], self::REGISTER);
        }
    }

    public function pull(): void
    {
        if ($this->packages->active()) {
            $this->jobs->push(self::TYPE, [], self::PULL);
        }
    }

    public function handle(array $jobs): array
    {
        $outcomes = [];
        foreach ($jobs as $job) {
            if ($job->uniqueKey === self::REGISTER) {
                $this->packages->registerAll();
            } else {
                $this->packages->pull();
            }
            $outcomes[$job->id] = JobOutcome::done();
        }

        return $outcomes;
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return $uniqueKey === self::REGISTER ? 'Hand the shop\'s words to the translation service' : 'Take back the translation service\'s translations';
    }
}
