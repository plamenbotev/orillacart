<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Exchange;

use OrillaCart\Exchange\Application\ColumnNames;

final class EnglishColumnNames implements ColumnNames
{
    public function of(string $id, string $english): string
    {
        return $english;
    }
}
