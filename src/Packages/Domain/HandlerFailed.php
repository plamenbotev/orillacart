<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

/**
 * A handler of an extension did not answer: it failed on purpose (`fail unavailable(…)`, `fail refused(…)`), or
 * it broke (an error, a budget used up). The kind says which, so the adapter raises what the shop expects.
 */
final class HandlerFailed extends \RuntimeException
{
    public const string UNAVAILABLE = 'unavailable';
    public const string REFUSED = 'refused';
    public const string ERROR = 'error';

    public function __construct(public readonly string $kind, string $message, public readonly mixed $data = null)
    {
        parent::__construct($message);
    }
}
