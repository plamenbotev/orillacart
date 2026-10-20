<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

/**
 * Where an export writes its rows, as they come: a CSV or JSON file streamed to the browser.
 */
interface ExportFile
{
    /**
     * @param list<string> $ids    the columns' ids
     * @param list<string> $labels their names, in the same order
     */
    public function start(array $ids, array $labels): void;

    /** @param list<string> $values in the columns' order */
    public function row(array $values): void;

    public function finish(): void;
}
