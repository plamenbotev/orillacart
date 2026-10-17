<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine\StrictMysqli;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

/**
 * Makes failed SQL throw under WordPress; see StrictErrors.
 */
final class Middleware implements MiddlewareInterface
{
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver);
    }
}
