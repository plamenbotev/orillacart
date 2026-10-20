<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application\Dto;

/**
 * One of the shop's own export columns: an id, a name, and how it reads its value.
 *
 * @template T
 */
final readonly class Column
{
    /**
     * @param \Closure(T): string $value
     * @param bool               $importable the import reads it back (product columns)
     */
    public function __construct(
        public string $id,
        public string $label,
        public \Closure $value,
        public bool $importable = false,
    ) {}
}
