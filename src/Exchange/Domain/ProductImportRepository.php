<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Domain;

interface ProductImportRepository
{
    public function find(int $id): ?ProductImport;

    /** The newest, for the screen to show what came of it. */
    public function latest(): ?ProductImport;

    /** A new import, or one changed as a whole: uploaded, started. */
    public function save(ProductImport $import): void;

    /**
     * Writes how far the import got and what came of its rows to its record by id, without the entity manager:
     * after each row, in the row's own transaction, so a row rolled back (and the loaded entities forgotten with
     * it) never makes a second record, and a worker killed mid-batch goes on after the last row it imported.
     */
    public function progress(ProductImport $import): void;

    public function remove(ProductImport $import): void;
}
