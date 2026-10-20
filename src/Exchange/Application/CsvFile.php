<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

/**
 * Writes an export as CSV to a stream, in UTF-8 with a byte-order mark so spreadsheets read its letters right.
 * A cell that would start a spreadsheet formula (=, +, -, @) and is not a number gets an apostrophe first, so
 * opening the file never runs what a customer typed; the import takes it off again.
 *
 * @param bool $headerIds the header names the columns by id, as the import reads them; otherwise by name
 */
final class CsvFile implements ExportFile
{
    public const string FORMULA = "/^[=+\\-@\t\r]/";

    /** @param resource $stream */
    public function __construct(
        private $stream,
        private readonly bool $headerIds = false,
    ) {}

    public function start(array $ids, array $labels): void
    {
        fwrite($this->stream, "\u{FEFF}");
        $this->write($this->headerIds ? $ids : $labels);
    }

    public function row(array $values): void
    {
        $this->write($values);
    }

    public function finish(): void
    {
        fflush($this->stream);
    }

    /** @param list<string> $values */
    private function write(array $values): void
    {
        fputcsv($this->stream, array_map(static fn(string $value): string => preg_match(self::FORMULA, $value) === 1 && !is_numeric($value) ? "'" . $value : $value, $values), ',', '"', '');
    }
}
