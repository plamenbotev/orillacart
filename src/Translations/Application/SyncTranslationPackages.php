<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application;

use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Jobs\Application\Jobs;

/**
 * Registers every object with the translation service, or takes back what its translators finished, in the
 * background: a shop's whole catalog of words is more than a request should wait for. Queued once however often
 * asked before it runs. Also one object's translations to the service, when its save had too many to send then.
 * Each job is reported as it is done.
 */
final readonly class SyncTranslationPackages implements JobHandler
{
    public const string TYPE = 'translations.packages';
    public const string REGISTER = 'register';
    public const string PULL = 'pull';
    /** One object's translations to the service, after a save with many strings: "send:{kind}:{id}". */
    public const string SEND = 'send';

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

    /** One job: the whole catalog registered, or everything finished taken back. */
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

    public static function sendKey(string $kind, int $id): string
    {
        return self::SEND . ":{$kind}:{$id}";
    }

    public function pull(): void
    {
        if ($this->packages->active()) {
            $this->jobs->push(self::TYPE, [], self::PULL);
        }
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        // Each job is settled as its exchange with the service is over, so a worker killed mid-batch repeats one.
        foreach ($jobs as $job) {
            $payload = $job->payload;
            if ($job->uniqueKey === self::REGISTER) {
                $this->packages->registerAll();
            } elseif (str_starts_with((string) $job->uniqueKey, self::SEND . ':') && \is_string($payload['kind'] ?? null) && is_numeric($payload['id'] ?? null)) {
                $this->packages->sendChanged($payload['kind'], (int) $payload['id']);
            } else {
                $this->packages->pull();
            }
            $progress->done($job);
        }

        return [];
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return match (true) {
            $uniqueKey === self::REGISTER => 'Hand the shop\'s words to the translation service',
            str_starts_with((string) $uniqueKey, self::SEND . ':') => \sprintf('Hand the translations of %s #%s to the translation service', \is_string($payload['kind'] ?? null) ? $payload['kind'] : '?', is_numeric($payload['id'] ?? null) ? (string) $payload['id'] : '?'),
            default => 'Take back the translation service\'s translations',
        };
    }
}
