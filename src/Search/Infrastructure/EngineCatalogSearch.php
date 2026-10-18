<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure;

use OrillaCart\Search\Application\CatalogSearch;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchResult;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Infrastructure\Doctrine\MysqlCatalogSearch;
use OrillaCart\Search\Infrastructure\OpenSearch\OpenSearchCatalogSearch;
use OrillaCart\Search\Infrastructure\OpenSearch\OpenSearchUnavailable;
use Psr\Log\LoggerInterface;

/**
 * Searches with the engine the shop chose: OpenSearch once its index is built, the database otherwise, and the
 * database too when OpenSearch does not answer, so the shop page never breaks.
 */
final readonly class EngineCatalogSearch implements CatalogSearch
{
    public function __construct(
        private EngineSettingsRepository $settings,
        private MysqlCatalogSearch $database,
        private OpenSearchCatalogSearch $openSearch,
        private LoggerInterface $logger,
    ) {}

    public function search(SearchQuery $query): SearchResult
    {
        if (!$this->settings->load()->searchesOpenSearch()) {
            return $this->database->search($query);
        }
        try {
            return $this->openSearch->search($query);
        } catch (OpenSearchUnavailable $e) {
            $this->logger->warning('The shop searched its database: {message}', ['message' => $e->getMessage()]);

            return $this->database->search($query);
        }
    }
}
