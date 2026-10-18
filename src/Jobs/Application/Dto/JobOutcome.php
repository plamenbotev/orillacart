<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * How a job went, as its handler says.
 */
final readonly class JobOutcome
{
    private const string DONE = 'done';
    private const string RESCHEDULE = 'reschedule';
    private const string RETRY = 'retry';
    private const string FAIL = 'fail';

    private function __construct(
        public string $kind,
        public ?\DateTimeImmutable $at = null,
        public string $error = '',
    ) {}

    public static function done(): self
    {
        return new self(self::DONE);
    }

    /** Done for now, and due again then. */
    public static function reschedule(\DateTimeImmutable $at): self
    {
        return new self(self::RESCHEDULE, $at);
    }

    /** Try again later, after the handler's wait, while attempts last. */
    public static function retry(string $error): self
    {
        return new self(self::RETRY, null, $error);
    }

    /** Give up at once: trying again cannot help, as when the order is gone. */
    public static function fail(string $error): self
    {
        return new self(self::FAIL, null, $error);
    }

    public function isDone(): bool
    {
        return $this->kind === self::DONE;
    }

    public function isRescheduled(): bool
    {
        return $this->kind === self::RESCHEDULE;
    }

    public function isRetry(): bool
    {
        return $this->kind === self::RETRY;
    }

    public function isFail(): bool
    {
        return $this->kind === self::FAIL;
    }
}
