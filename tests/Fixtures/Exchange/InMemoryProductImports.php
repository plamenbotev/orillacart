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
        // A copy, as the database gives: what is changed on it is kept only once saved.
        return isset($this->imports[$id]) ? clone $this->imports[$id] : null;
    }

    public function latest(): ?ProductImport
    {
        return $this->imports === [] ? null : clone $this->imports[max(array_keys($this->imports))];
    }

    public function save(ProductImport $import): void
    {
        $id = new \ReflectionProperty(ProductImport::class, 'id');
        if ($id->getValue($import) === null) {
            $id->setValue($import, $this->nextId++);
        }
        $this->imports[$import->id()] = clone $import;
    }

    /** @var int how many times progress was written */
    public int $progressed = 0;

    /** Thrown by a progress written once this many more were, as a database gone away would. */
    public ?\Throwable $breaks = null;
    public int $breaksAfter = 0;

    public function progress(ProductImport $import): void
    {
        if ($this->breaks !== null && $this->breaksAfter-- <= 0) {
            [$breaks, $this->breaks] = [$this->breaks, null];

            throw $breaks;
        }
        ++$this->progressed;
        // As the database: the record by id, never a second one.
        if (!isset($this->imports[$import->id()])) {
            throw new \LogicException('No import record to write progress to.');
        }
        $this->imports[$import->id()] = clone $import;
    }

    public function remove(ProductImport $import): void
    {
        unset($this->imports[$import->id()]);
    }
}
