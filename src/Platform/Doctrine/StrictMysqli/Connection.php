<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine\StrictMysqli;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;

final class Connection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): StatementInterface
    {
        return new Statement(StrictErrors::run(fn(): StatementInterface => parent::prepare($sql)));
    }

    public function query(string $sql): ResultInterface
    {
        return new Result(StrictErrors::run(fn(): ResultInterface => parent::query($sql)));
    }

    public function exec(string $sql): int|string
    {
        return StrictErrors::run(fn(): int|string => parent::exec($sql));
    }

    public function lastInsertId(): int|string
    {
        return StrictErrors::run(fn(): int|string => parent::lastInsertId());
    }

    public function beginTransaction(): void
    {
        StrictErrors::run(fn() => parent::beginTransaction());
    }

    public function commit(): void
    {
        StrictErrors::run(fn() => parent::commit());
    }

    public function rollBack(): void
    {
        StrictErrors::run(fn() => parent::rollBack());
    }

    public function getServerVersion(): string
    {
        return StrictErrors::run(fn(): string => parent::getServerVersion());
    }
}
