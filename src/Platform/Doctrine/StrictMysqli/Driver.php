<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine\StrictMysqli;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use OrillaCart\Platform\Doctrine\WordPressConnection;

final class Driver extends AbstractDriverMiddleware
{
    public function connect(#[\SensitiveParameter] array $params): ConnectionInterface
    {
        // WordPress's own connection where it has one; tools without WordPress open their own.
        if (WordPressConnection::available()) {
            return new Connection(new WordPressConnection());
        }

        return new Connection(StrictErrors::run(fn(): ConnectionInterface => parent::connect($params)));
    }
}
