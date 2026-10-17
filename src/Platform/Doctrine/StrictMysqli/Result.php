<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine\StrictMysqli;

use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;

final class Result extends AbstractResultMiddleware
{
    public function fetchNumeric(): array|false
    {
        return StrictErrors::run(fn(): array|false => parent::fetchNumeric());
    }

    public function fetchAssociative(): array|false
    {
        return StrictErrors::run(fn(): array|false => parent::fetchAssociative());
    }

    public function fetchOne(): mixed
    {
        return StrictErrors::run(fn(): mixed => parent::fetchOne());
    }

    public function fetchAllNumeric(): array
    {
        return StrictErrors::run(fn(): array => parent::fetchAllNumeric());
    }

    public function fetchAllAssociative(): array
    {
        return StrictErrors::run(fn(): array => parent::fetchAllAssociative());
    }

    public function fetchFirstColumn(): array
    {
        return StrictErrors::run(fn(): array => parent::fetchFirstColumn());
    }
}
