<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Exchange\Application\Dto\ColumnView;
use OrillaCart\Exchange\Application\Dto\ImportView;
use OrillaCart\Exchange\Domain\ExchangeRuleViolation;
use OrillaCart\Exchange\Domain\ImportNotFound;
use OrillaCart\Exchange\Domain\ImportStatus;
use OrillaCart\Exchange\Domain\ProductImport;
use OrillaCart\Exchange\Domain\ProductImportRepository;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Files\PrivateFiles;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Imports a products file, as the products export writes it. The upload is read first, and the shop manager sees
 * which columns it changes before starting; a job then imports it a batch of rows at a time, so a file of any
 * size never runs into a request's time. A refused row is reported with why, and the others go on. The file is
 * kept privately while it is imported, and deleted after.
 */
final readonly class ProductImports
{
    public const int MAX_ROWS = 50000;
    /** Rows a job imports before it gives the lane back. */
    private const int BATCH = 50;
    /** Seconds a batch may take before it stops early, however many rows are left. */
    private const int BUDGET = 20;
    /** Columns that name the row's product, read but not changed. */
    private const array NAMING = ['id', 'variation_id'];

    public function __construct(
        private ProductImportRepository $imports,
        private ProductColumns $columns,
        private ColumnChoice $choice,
        private ProductRows $rows,
        private PrivateFiles $files,
        private Jobs $jobs,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * Reads an uploaded file and keeps it, ready to start.
     *
     * @throws ExchangeRuleViolation for a file that is not a products file
     */
    public function upload(string $path, string $filename, ?int $userId): ImportView
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ExchangeRuleViolation('The file could not be read.', 'orillacart_invalid_import');
        }
        try {
            $header = self::header($handle);
            $rows = 0;
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if (self::blank($row)) {
                    continue;
                }
                if (++$rows > self::MAX_ROWS) {
                    throw new ExchangeRuleViolation(\sprintf('A file has up to %d rows; split it.', self::MAX_ROWS), 'orillacart_invalid_import');
                }
            }
            if ($rows === 0) {
                throw new ExchangeRuleViolation('The file has no rows after its header.', 'orillacart_invalid_import');
            }
        } finally {
            fclose($handle);
        }
        $readable = array_values(array_filter($header, $this->reads(...)));
        if (array_intersect($readable, ['id', 'variation_id', 'sku', 'name']) === []) {
            throw new ExchangeRuleViolation('The file\'s first row names no column the import knows, such as id, sku or name: export the products to see them.', 'orillacart_invalid_import');
        }
        $stored = $this->files->store($path, $filename);
        $import = new ProductImport($stored->key, $filename, $header, $rows, $this->dataStart($stored->key), $userId, $this->clock->now());
        $this->imports->save($import);

        return $this->view($import);
    }

    /**
     * @throws ImportNotFound
     * @throws ExchangeRuleViolation once it started
     */
    public function start(int $id): ImportView
    {
        $import = $this->get($id);
        $import->start();
        $this->imports->save($import);
        $this->jobs->push(ImportProducts::TYPE, ['import' => $id], "import:{$id}");

        return $this->view($import);
    }

    /**
     * Imports the next batch of rows; ImportProducts runs it for its job.
     *
     * @return bool whether rows are left
     *
     * @throws ImportNotFound
     */
    public function continue(int $id): bool
    {
        $import = $this->get($id);
        if ($import->status() !== ImportStatus::Running) {
            return false;
        }
        $path = $this->files->path($import->fileKey());
        $handle = $path === null ? false : fopen($path, 'rb');
        if ($handle === false) {
            $import->failedRow($import->done() + 2, 'The file is gone; upload it again.');
            $import->finish($this->clock->now());
            $this->imports->save($import);

            return false;
        }
        $started = $this->clock->now()->getTimestamp();
        $columns = $import->columns();
        try {
            fseek($handle, $import->position());
            for ($count = 0; $count < self::BATCH && $this->clock->now()->getTimestamp() - $started < self::BUDGET; ++$count) {
                $row = fgetcsv($handle, null, ',', '"', '');
                if ($row === false) {
                    break;
                }
                if (self::blank($row)) {
                    --$count;
                    continue;
                }
                $this->row($import, $columns, $row);
            }
            $import->reached((int) ftell($handle));
            $finished = fgetcsv($handle, null, ',', '"', '') === false && feof($handle);
        } finally {
            fclose($handle);
        }
        if ($finished) {
            $import->finish($this->clock->now());
            $this->files->delete($import->fileKey());
        }
        $this->imports->save($import);

        return !$finished;
    }

    /**
     * @throws ImportNotFound
     */
    public function import(int $id): ImportView
    {
        return $this->view($this->get($id));
    }

    /** The newest import, for the screen to show what came of it. */
    public function latest(): ?ImportView
    {
        $import = $this->imports->latest();

        return $import === null ? null : $this->view($import);
    }

    /**
     * Forgets an import that is not running, and its file.
     *
     * @throws ImportNotFound
     * @throws ExchangeRuleViolation while it runs
     */
    public function discard(int $id): void
    {
        $import = $this->get($id);
        if ($import->status() === ImportStatus::Running) {
            throw new ExchangeRuleViolation('The import is running; it ends by itself.', 'orillacart_import_running');
        }
        $this->files->delete($import->fileKey());
        $this->imports->remove($import);
    }

    /**
     * @param list<string>       $columns
     * @param array<int, ?string> $cells
     */
    private function row(ProductImport $import, array $columns, array $cells): void
    {
        $line = $import->done() + 2;
        $row = [];
        foreach ($columns as $index => $column) {
            if ($this->reads($column)) {
                $row[$column] = (string) ($cells[$index] ?? '');
            }
        }
        try {
            $this->rows->import($row) ? $import->createdRow() : $import->updatedRow();
        } catch (InvalidFields $e) {
            $import->failedRow($line, implode(' ', array_map(static fn(array $violation): string => "{$violation['field']}: {$violation['message']}", $e->violations)));
        } catch (RuleViolation|\InvalidArgumentException $e) {
            $import->failedRow($line, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Row {line} of products import {import} failed: {message}', ['line' => $line, 'import' => $import->id(), 'message' => $e->getMessage(), 'exception' => $e]);
            $import->failedRow($line, 'It could not be saved; the shop\'s log says why.');
        }
    }

    /** Whether the import reads the column: one naming the row, or one it changes. */
    private function reads(string $column): bool
    {
        $all = $this->columns->all();

        return \in_array($column, self::NAMING, true) || (isset($all[$column]) && ColumnChoice::importable($all[$column]));
    }

    private function view(ProductImport $import): ImportView
    {
        $all = $this->columns->all();
        $read = array_values(array_filter($import->columns(), $this->reads(...)));
        $views = array_values(array_map(fn(string $column): ColumnView => $this->choice->view($all[$column], true), array_filter($read, static fn(string $column): bool => isset($all[$column]))));

        return ImportView::fromImport($import, $views, array_values(array_diff($import->columns(), $read)));
    }

    /**
     * @throws ImportNotFound
     */
    private function get(int $id): ProductImport
    {
        return $this->imports->find($id) ?? throw new ImportNotFound($id);
    }

    /**
     * The header's column ids, lower case, without the byte-order mark the export writes.
     *
     * @param resource $handle
     *
     * @return list<string>
     *
     * @throws ExchangeRuleViolation
     */
    private static function header($handle): array
    {
        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false || self::blank($header)) {
            throw new ExchangeRuleViolation('The file is empty.', 'orillacart_invalid_import');
        }
        $columns = array_map(static fn(?string $cell): string => strtolower(trim(str_replace("\u{FEFF}", '', (string) $cell))), $header);
        if (\count($columns) !== \count(array_unique($columns))) {
            throw new ExchangeRuleViolation('The file names a column twice.', 'orillacart_invalid_import');
        }

        return $columns;
    }

    /** Where the stored file's first row after the header starts. */
    private function dataStart(string $key): int
    {
        $path = $this->files->path($key);
        $handle = $path === null ? false : fopen($path, 'rb');
        if ($handle === false) {
            throw new ExchangeRuleViolation('The file could not be kept.', 'orillacart_invalid_import');
        }
        fgetcsv($handle, null, ',', '"', '');
        $position = (int) ftell($handle);
        fclose($handle);

        return $position;
    }

    /** @param array<int, ?string> $row */
    private static function blank(array $row): bool
    {
        return array_filter($row, static fn(?string $cell): bool => $cell !== null && trim($cell) !== '') === [];
    }
}
