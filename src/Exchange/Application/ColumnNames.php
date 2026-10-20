<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

/**
 * The shop's own columns' names in the site's language; add-ons name theirs.
 */
interface ColumnNames
{
    public function of(string $id, string $english): string;
}
