<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

/**
 * Which engine the shop searches with, and how it reaches OpenSearch. The database's tables are kept up to date
 * whichever engine searches, so the shop can fall back to them; OpenSearch answers shoppers once its index was
 * built (ready).
 */
final readonly class EngineSettings
{
    /**
     * @param string $host  where OpenSearch listens: "https://search.example.com:9200"
     * @param string $index the index's name, so several shops can share one server
     */
    public function __construct(
        public SearchEngine $engine = SearchEngine::Database,
        public string $host = '',
        public string $username = '',
        public string $password = '',
        public string $index = 'orillacart',
        public bool $ready = false,
    ) {}

    public function searchesOpenSearch(): bool
    {
        return $this->engine === SearchEngine::OpenSearch && $this->ready;
    }

    public function indexesOpenSearch(): bool
    {
        return $this->engine === SearchEngine::OpenSearch && $this->host !== '';
    }

    public function built(): self
    {
        return new self($this->engine, $this->host, $this->username, $this->password, $this->index, true);
    }
}
