<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Exchange\Application\Dto\Column;
use OrillaCart\Exchange\Application\Dto\ColumnView;
use OrillaCart\Exchange\Application\Dto\ImportView;
use OrillaCart\Exchange\Application\Dto\ProductRow;
use OrillaCart\Exchange\Domain\ExchangeRuleViolation;
use OrillaCart\Exchange\Domain\ImportNotFound;
use OrillaCart\Exchange\Domain\ProductImport;
use OrillaCart\Exchange\Domain\ProductImportRepository;
use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\TextEncoding;
use OrillaCart\Shared\Files\PrivateFiles;
use OrillaCart\Shared\Persistence\Transactions;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Imports a products file, as the products export writes it. The upload is read first, and the shop manager sees
 * which columns it changes before starting; a job then imports it a batch of rows at a time, so a file of any
 * size never runs into a request's time. A refused row is reported with why, by its line in the file, and the
 * others go on. Each row runs in a transaction of its own, as the user who uploaded the file, and how far the
 * import got is written with it: a worker stopped mid-batch goes on after the last row it imported. A file in
 * Windows-1252, as Excel saves CSV on Windows, is read as such. The file is kept privately while it is imported,
 * and deleted after; an import that fails for good ends as failed, and its file goes too.
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
        private ProductPosts $posts,
        private PrivateFiles $files,
        private Jobs $jobs,
        private Transactions $transactions,
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
        $converted = $this->utf8($path);
        try {
            return $this->uploaded($converted ?? $path, $filename, $userId);
        } finally {
            if ($converted !== null) {
                @unlink($converted);
            }
        }
    }

    /**
     * A copy of the file in UTF-8 when it is in Windows-1252; null when it is in UTF-8 already.
     *
     * @throws ExchangeRuleViolation for a file in neither
     */
    private function utf8(string $path): ?string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new ExchangeRuleViolation('The file could not be read.', 'orillacart_invalid_import');
        }
        $text = TextEncoding::utf8($bytes) ?? throw new ExchangeRuleViolation('The file is not text in UTF-8 or Windows-1252: save it as "CSV UTF-8".', 'orillacart_invalid_import');
        if ($text === $bytes) {
            return null;
        }
        $copy = tempnam(sys_get_temp_dir(), 'orillacart-import');
        if ($copy === false || file_put_contents($copy, $text) === false) {
            throw new ExchangeRuleViolation('The file could not be read.', 'orillacart_invalid_import');
        }

        return $copy;
    }

    /**
     * @throws ExchangeRuleViolation
     */
    private function uploaded(string $path, string $filename, ?int $userId): ImportView
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
                    throw new ExchangeRuleViolation('A file has up to %d rows; split it.', 'orillacart_invalid_import', values: [self::MAX_ROWS]);
                }
            }
            if ($rows === 0) {
                throw new ExchangeRuleViolation('The file has no rows after its header.', 'orillacart_invalid_import');
            }
        } finally {
            fclose($handle);
        }
        $all = $this->columns->all();
        $readable = array_values(array_filter($header, static fn(string $column): bool => self::reads($all, $column)));
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
        // A copy the entity manager does not hold: what the batch changes is written by progress() alone.
        $import = clone $this->get($id);
        if (!$import->isRunning()) {
            return false;
        }
        $path = $this->files->path($import->fileKey());
        $handle = $path === null ? false : fopen($path, 'rb');
        if ($handle === false) {
            $this->failed($import, 'The file is gone; upload it again.');

            return false;
        }
        $started = $this->clock->now()->getTimestamp();
        $header = $import->columns();
        // Built once for the batch: the shop's columns, the stockrooms' and the add-ons'.
        $all = $this->columns->all();
        try {
            fseek($handle, $import->position());
            for ($count = 0; $count < self::BATCH && $this->clock->now()->getTimestamp() - $started < self::BUDGET;) {
                $cells = fgetcsv($handle, null, ',', '"', '');
                if ($cells === false) {
                    break;
                }
                $line = $import->line();
                // A row spans a line, and one more for each line break in its quoted cells.
                $next = $line + 1 + array_sum(array_map(static fn(?string $cell): int => substr_count((string) $cell, "\n"), $cells));
                $position = (int) ftell($handle);
                if (self::blank($cells)) {
                    // Counted for the next row's number; written with it.
                    $import->reached($position, $next);
                    continue;
                }
                ++$count;
                $import = $this->row($import, $header, $all, $cells, $line, $position, $next);
            }
            $finished = fgetcsv($handle, null, ',', '"', '') === false && feof($handle);
        } finally {
            fclose($handle);
        }
        if ($finished) {
            $import = clone $import;
            $import->finish($this->clock->now());
        }
        // Blank lines at the batch's end, or the end itself.
        $this->imports->progress($import);
        if ($finished) {
            $this->files->delete($import->fileKey());
        }

        return !$finished;
    }

    /**
     * The import's job failed for good: the import ends as failed, with why, and its file goes. The rows imported
     * before stay.
     */
    public function fail(int $id, string $why): void
    {
        $import = $this->imports->find($id);
        if ($import !== null && $import->isRunning()) {
            $this->failed($import, $why);
        }
    }

    private function failed(ProductImport $import, string $why): void
    {
        $import->fail($why, $this->clock->now());
        $this->imports->progress($import);
        $this->files->delete($import->fileKey());
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
     * Forgets an import that is not running, and its file. One left running with no job to go on with it (its
     * worker stopped for good) is forgotten too.
     *
     * @throws ImportNotFound
     * @throws ExchangeRuleViolation while it runs
     */
    public function discard(int $id): void
    {
        $import = $this->get($id);
        if ($import->isRunning() && !$this->stalled($import)) {
            throw new ExchangeRuleViolation('The import is running; it ends by itself.', 'orillacart_import_running');
        }
        $this->files->delete($import->fileKey());
        $this->imports->remove($import);
    }

    /**
     * Imports one row in a transaction of its own, as the user who uploaded the file, and writes how far the
     * import got with it. The import's entity is never saved again after a row: a row rolled back makes the
     * entity manager forget it, and saving it would make a new record.
     *
     * @param list<string>                                       $header
     * @param array<string, Column<ProductRow>|ProductColumn> $all
     * @param array<int, ?string>                                $cells
     *
     * @return ProductImport with the row counted
     */
    private function row(ProductImport $import, array $header, array $all, array $cells, int $line, int $position, int $next): ProductImport
    {
        $counted = clone $import;
        $counted->reached($position, $next);
        if (\count($cells) !== \count($header)) {
            $counted->failedRow($line, \sprintf('The row has %1$d cells; the header names %2$d columns.', \count($cells), \count($header)));
            $this->imports->progress($counted);

            return $counted;
        }
        $row = [];
        foreach ($header as $index => $column) {
            if (self::reads($all, $column)) {
                $row[$column] = (string) ($cells[$index] ?? '');
            }
        }
        try {
            $this->posts->asUser($import->userId(), fn() => $this->transactions->run(function () use ($counted, $row, $all, $line): void {
                [$created, $refused] = $this->rows->import($row, $counted->userId(), $all);
                match (true) {
                    $refused !== null => $counted->failedRow($line, $refused),
                    $created => $counted->createdRow(),
                    default => $counted->updatedRow(),
                };
                $this->imports->progress($counted);
            }));

            return $counted;
        } catch (InvalidFields $e) {
            $why = implode(' ', array_map(static fn(array $violation): string => "{$violation['field']}: {$violation['message']}", $e->violations));
        } catch (RuleViolation|\InvalidArgumentException $e) {
            $why = $e->getMessage();
        } catch (\Throwable $e) {
            $this->logger->error('Row {line} of products import {import} failed: {message}', ['line' => $line, 'import' => $import->id(), 'message' => $e->getMessage(), 'exception' => $e]);
            $why = 'It could not be saved; the shop\'s log says why.';
        }
        // Rolled back: counted from the import as it was before the row.
        $counted = clone $import;
        $counted->reached($position, $next);
        $counted->failedRow($line, $why);
        $this->imports->progress($counted);

        return $counted;
    }

    /**
     * Whether the import reads the column: one naming the row, or one it changes.
     *
     * @param array<string, Column<ProductRow>|ProductColumn> $all
     */
    private static function reads(array $all, string $column): bool
    {
        return \in_array($column, self::NAMING, true) || (isset($all[$column]) && ColumnChoice::importable($all[$column]));
    }

    private function view(ProductImport $import): ImportView
    {
        $all = $this->columns->all();
        $read = array_values(array_filter($import->columns(), static fn(string $column): bool => self::reads($all, $column)));
        $views = array_values(array_map(fn(string $column): ColumnView => $this->choice->view($all[$column], true), array_filter($read, static fn(string $column): bool => isset($all[$column]))));

        return ImportView::fromImport($import, $views, array_values(array_diff($import->columns(), $read)), $this->stalled($import));
    }

    /** Running with no job left to go on with it. */
    private function stalled(ProductImport $import): bool
    {
        return $import->isRunning() && $this->jobs->pending(ImportProducts::TYPE) === 0;
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
