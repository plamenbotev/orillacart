<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Search\Infrastructure\EngineCatalogSearch;
use OrillaCart\Search\Infrastructure\OpenSearch\OpenSearchClient;
use OrillaCart\Search\Infrastructure\WordPress\OptionEngineSettingsRepository;

/**
 * The storefront search on the database engine (MySQL FULLTEXT and the search tables), and on it too for a minute
 * after OpenSearch failed, without asking OpenSearch again meanwhile.
 */
final class CatalogSearchTest extends CatalogSearchContract
{
    public function testAfterOpenSearchFailsTheDatabaseAnswersForAMinuteWithoutAskingItAgain(): void
    {
        // Logged once for the first failure and once after the minute: not on the search between.
        $this->expectOutputRegex('/^(?:[^\n]*OpenSearch failed, so the shop searches its database for 60 seconds[^\n]*\n){2}$/');
        $before = get_option(OptionEngineSettingsRepository::OPTION);
        update_option(OptionEngineSettingsRepository::OPTION, ['engine' => 'opensearch', 'host' => 'http://opensearch.invalid:9200', 'username' => '', 'password' => '', 'index' => 'orillacart', 'ready' => true], true);
        $asked = [];
        $down = static function (mixed $answer, array $arguments, string $url) use (&$asked): mixed {
            if (!str_starts_with($url, 'http://opensearch.invalid:9200')) {
                return $answer;
            }
            $asked[] = [$url, $arguments['timeout'] ?? null];

            return new \WP_Error('http_request_failed', 'Operation timed out');
        };
        add_filter('pre_http_request', $down, 10, 3);
        try {
            $search = self::kernel()->container()->get(ShopSearch::class);
            self::assertInstanceOf(ShopSearch::class, $search);
            $first = $search->search(new SearchQuery(text: 'lamp'))->ids;
            $second = $search->search(new SearchQuery(text: 'lamp'))->ids;

            self::assertNotSame([], $first, 'the database answered');
            self::assertSame($first, $second);
            self::assertSame([['http://opensearch.invalid:9200/orillacart/_search', OpenSearchClient::SEARCH_TIMEOUT]], $asked, 'asked once, briefly: the second search went to the database at once');
            $until = get_option(EngineCatalogSearch::OUTAGE_OPTION);
            self::assertIsNumeric($until);
            self::assertGreaterThan(time(), (int) $until, 'remembered for a minute');

            // The minute is over: OpenSearch is asked again.
            update_option(EngineCatalogSearch::OUTAGE_OPTION, (string) (time() - 1), true);
            $search->search(new SearchQuery(text: 'lamp'));
            self::assertCount(2, $asked);
        } finally {
            remove_filter('pre_http_request', $down, 10);
            $before === false ? delete_option(OptionEngineSettingsRepository::OPTION) : update_option(OptionEngineSettingsRepository::OPTION, $before, true);
            update_option(EngineCatalogSearch::OUTAGE_OPTION, '0', true);
        }
    }
}
