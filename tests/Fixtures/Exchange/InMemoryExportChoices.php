<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Exchange;

use OrillaCart\Exchange\Domain\ExportChoices;
use OrillaCart\Exchange\Domain\ExportKind;

final class InMemoryExportChoices implements ExportChoices
{
    /** @var array<string, list<string>> */
    public array $chosen = [];

    public function chosen(ExportKind $kind): ?array
    {
        return $this->chosen[$kind->value] ?? null;
    }

    public function choose(ExportKind $kind, array $columns): void
    {
        $this->chosen[$kind->value] = $columns;
    }
}
