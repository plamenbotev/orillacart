<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Domain;

interface ProductImportRepository
{
    public function find(int $id): ?ProductImport;

    /** The newest, for the screen to show what came of it. */
    public function latest(): ?ProductImport;

    public function save(ProductImport $import): void;

    public function remove(ProductImport $import): void;
}
