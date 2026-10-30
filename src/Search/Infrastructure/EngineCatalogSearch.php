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
 * database too when OpenSearch does not answer, so the shop page never breaks. A failure is remembered for a
 * minute, in an autoloaded option (a transient is a query per request without an object cache): meanwhile the
 * database answers at once instead of every listing waiting on a server that is down, and the outage is logged
 * once a minute, not on every request.
 */
final readonly class EngineCatalogSearch implements CatalogSearch
{
    /** Until when (a Unix time) the database answers because OpenSearch failed; stored autoloaded. */
    public const string OUTAGE_OPTION = 'orillacart_search_outage';
    /** How long a failure is remembered. */
    public const int OUTAGE_SECONDS = 60;

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
        $until = get_option(self::OUTAGE_OPTION, '0');
        if (is_numeric($until) && (int) $until > time()) {
            return $this->database->search($query);
        }
        try {
            return $this->openSearch->search($query);
        } catch (OpenSearchUnavailable $e) {
            update_option(self::OUTAGE_OPTION, (string) (time() + self::OUTAGE_SECONDS), true);
            $this->logger->warning('OpenSearch failed, so the shop searches its database for {seconds} seconds: {message}', ['seconds' => self::OUTAGE_SECONDS, 'message' => $e->getMessage()]);

            return $this->database->search($query);
        }
    }
}
