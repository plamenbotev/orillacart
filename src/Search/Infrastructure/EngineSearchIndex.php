<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure;

use OrillaCart\Search\Application\SearchIndex;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Infrastructure\Doctrine\MysqlSearchIndex;
use OrillaCart\Search\Infrastructure\OpenSearch\OpenSearchIndex;

/**
 * Keeps documents in the database's tables always (the fallback, and the facets' labels), and in OpenSearch too
 * when the shop chose it. When OpenSearch fails, the job fails and is built again later, both together.
 */
final readonly class EngineSearchIndex implements SearchIndex
{
    public function __construct(
        private EngineSettingsRepository $settings,
        private MysqlSearchIndex $database,
        private OpenSearchIndex $openSearch,
    ) {}

    public function put(array $documents): void
    {
        $this->database->put($documents);
        if ($this->settings->load()->indexesOpenSearch()) {
            $this->openSearch->put($documents);
        }
    }

    public function remove(array $productIds): void
    {
        $this->database->remove($productIds);
        if ($this->settings->load()->indexesOpenSearch()) {
            $this->openSearch->remove($productIds);
        }
    }
}
