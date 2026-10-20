<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application\Dto;

use OrillaCart\Exchange\Domain\ProductImport;

/**
 * A products import, as the screen shows it: the file's columns, what it holds, and what came of its rows.
 */
final readonly class ImportView
{
    /**
     * @param list<ColumnView> $columns the file's columns the import reads
     * @param list<string>     $ignored the file's columns it does not: unknown, or only exported
     * @param list<string>     $errors  "Row 7: …", the first ProductImport::MAX_ERRORS of them
     */
    public function __construct(
        public int $id,
        public string $filename,
        public string $status,
        public array $columns,
        public array $ignored,
        public int $rows,
        public int $done,
        public int $created,
        public int $updated,
        public int $failed,
        public array $errors,
        public string $createdAt,
        public ?string $finishedAt,
    ) {}

    /**
     * @param list<ColumnView> $columns
     * @param list<string>     $ignored
     */
    public static function fromImport(ProductImport $import, array $columns, array $ignored): self
    {
        return new self(
            $import->id(),
            $import->filename(),
            $import->status()->value,
            $columns,
            $ignored,
            $import->rows(),
            $import->done(),
            $import->created(),
            $import->updated(),
            $import->failed(),
            $import->errors(),
            $import->createdAt()->format(\DATE_ATOM),
            $import->finishedAt()?->format(\DATE_ATOM),
        );
    }
}
