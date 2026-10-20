<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Domain;

/**
 * The columns the shop manager chose to export, by kind; none chosen yet means all of them.
 */
interface ExportChoices
{
    /** @return list<string>|null column ids; null while the shop never chose */
    public function chosen(ExportKind $kind): ?array;

    /** @param list<string> $columns */
    public function choose(ExportKind $kind, array $columns): void;
}
