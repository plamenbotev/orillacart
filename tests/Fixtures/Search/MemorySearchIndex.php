<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Search;

use OrillaCart\Search\Application\Dto\SearchDocument;
use OrillaCart\Search\Application\IndexRefusedFields;
use OrillaCart\Search\Application\SearchIndex;

final class MemorySearchIndex implements SearchIndex
{
    /** @var array<int, SearchDocument> */
    public array $documents = [];
    /** @var list<int> how many documents each write held */
    public array $writes = [];
    /** How many writes to refuse for fields the index does not know, as a strict mapping behind this version refuses. */
    public int $refusals = 0;

    public function put(array $documents): void
    {
        if ($this->refusals > 0) {
            --$this->refusals;

            throw new IndexRefusedFields('OpenSearch refused a document of the batch: mapping set to strict, dynamic introduction of [words] within [_doc] is not allowed');
        }
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
