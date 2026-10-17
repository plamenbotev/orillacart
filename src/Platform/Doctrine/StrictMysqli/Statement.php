<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine\StrictMysqli;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result as ResultInterface;

final class Statement extends AbstractStatementMiddleware
{
    public function execute(): ResultInterface
    {
        return new Result(StrictErrors::run(fn(): ResultInterface => parent::execute()));
    }
}
