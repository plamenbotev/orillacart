<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

/**
 * A site's turn at its jobs threw, and the failure was logged on that site already.
 */
final class SiteTurnFailed extends \RuntimeException
{
    public function __construct(\Throwable $cause)
    {
        parent::__construct($cause->getMessage(), 0, $cause);
    }
}
