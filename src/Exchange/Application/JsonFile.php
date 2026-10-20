<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

/**
 * Writes an export as a JSON list of objects, keyed by column id, one row at a time.
 */
final class JsonFile implements ExportFile
{
    /** @var list<string> */
    private array $ids = [];
    private bool $first = true;

    /** @param resource $stream */
    public function __construct(private $stream) {}

    public function start(array $ids, array $labels): void
    {
        $this->ids = $ids;
        fwrite($this->stream, '[');
    }

    public function row(array $values): void
    {
        fwrite($this->stream, ($this->first ? "\n" : ",\n") . json_encode(array_combine($this->ids, $values), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        $this->first = false;
    }

    public function finish(): void
    {
        fwrite($this->stream, "\n]\n");
        fflush($this->stream);
    }
}
