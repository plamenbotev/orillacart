<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain;

/**
 * `fail unavailable("…")`: the script says it cannot go on, and why. The kind decides what the shop does with it:
 * `unavailable` is a provider that cannot be reached (tried again later), `refused` an answer to show the caller,
 * `error` a problem to log.
 */
final class ScriptFailure extends \RuntimeException implements Catchable
{
    public const string UNAVAILABLE = 'unavailable';
    public const string REFUSED = 'refused';
    public const string ERROR = 'error';
    public const string ASSERTION = 'assertion';

    public function __construct(public readonly string $kind, string $message, public readonly mixed $data = null)
    {
        parent::__construct($message);
    }

    public function value(): array
    {
        return ['kind' => $this->kind, 'message' => $this->getMessage(), 'data' => $this->data];
    }
}
