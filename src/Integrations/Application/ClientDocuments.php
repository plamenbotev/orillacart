<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application;

/**
 * Client ID metadata documents: an OAuth client named by an https address describes itself at that address.
 */
interface ClientDocuments
{
    /**
     * The document as JSON, fetched (and kept a while); null when it cannot be had.
     *
     * @return array<string, mixed>|null
     */
    public function fetch(string $url): ?array;
}
