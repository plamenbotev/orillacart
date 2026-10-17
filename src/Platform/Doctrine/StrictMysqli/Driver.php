<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine\StrictMysqli;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class Driver extends AbstractDriverMiddleware
{
    public function connect(#[\SensitiveParameter] array $params): ConnectionInterface
    {
        return new Connection(StrictErrors::run(fn(): ConnectionInterface => parent::connect($params)));
    }
}
