<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Exchange;

use OrillaCart\Exchange\Domain\ProductImport;
use OrillaCart\Exchange\Domain\ProductImportRepository;

final class InMemoryProductImports implements ProductImportRepository
{
    /** @var array<int, ProductImport> */
    public array $imports = [];
    private int $nextId = 1;

    public function find(int $id): ?ProductImport
    {
        return $this->imports[$id] ?? null;
    }

    public function latest(): ?ProductImport
    {
        return $this->imports === [] ? null : $this->imports[max(array_keys($this->imports))];
    }

    public function save(ProductImport $import): void
    {
        $id = new \ReflectionProperty(ProductImport::class, 'id');
        if ($id->getValue($import) === null) {
            $id->setValue($import, $this->nextId++);
        }
        $this->imports[$import->id()] = $import;
    }

    public function remove(ProductImport $import): void
    {
        unset($this->imports[$import->id()]);
    }
}
