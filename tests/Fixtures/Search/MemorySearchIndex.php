<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Search;

use OrillaCart\Search\Application\Dto\SearchDocument;
use OrillaCart\Search\Application\SearchIndex;

final class MemorySearchIndex implements SearchIndex
{
    /** @var array<int, SearchDocument> */
    public array $documents = [];
    /** @var list<int> how many documents each write held */
    public array $writes = [];

    public function put(array $documents): void
    {
        $this->writes[] = \count($documents);
        foreach ($documents as $document) {
            $this->documents[$document->productId] = $document;
        }
    }

    public function productIds(): array
    {
        return array_keys($this->documents);
    }

    public function remove(array $productIds): void
    {
        foreach ($productIds as $id) {
            unset($this->documents[$id]);
        }
    }
}
