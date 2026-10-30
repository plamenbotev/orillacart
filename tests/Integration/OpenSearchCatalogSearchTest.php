<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Search\Infrastructure\OpenSearch\OpenSearchServer;
use OrillaCart\Search\Infrastructure\WordPress\OptionEngineSettingsRepository;

/**
 * The storefront search on OpenSearch, answering as the database engine does. Runs when ORILLACART_OPENSEARCH
 * names a server (http://oc-opensearch:9200 next to wp-env); skipped otherwise.
 */
final class OpenSearchCatalogSearchTest extends CatalogSearchContract
{
    private const string INDEX = 'orillacart_test';

    protected function setUp(): void
    {
        if (self::host() === '') {
            self::markTestSkipped('No OpenSearch server: set ORILLACART_OPENSEARCH.');
        }
        $this->deleteIndex();
        parent::setUp();

        $this->actAs('administrator');
        $switched = $this->data($this->request('PUT', '/search-engine', ['engine' => 'opensearch', 'host' => self::host(), 'index' => self::INDEX], nonce: true));
        self::assertTrue($switched['works'], (string) wp_json_encode($switched));
        self::assertFalse($switched['ready'], 'the database answers until the index is built');
        wp_set_current_user(0);
        $this->work();
        $this->actAs('administrator');
        self::assertTrue($this->data($this->request('GET', '/search-engine'))['ready']);
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        if (self::host() !== '') {
            delete_option(OptionEngineSettingsRepository::OPTION);
            $this->deleteIndex();
        }
        parent::tearDown();
    }

    public function testAServerThatDoesNotAnswerIsNotSwitchedTo(): void
    {
        $this->actAs('administrator');
        $refused = $this->data($this->request('PUT', '/search-engine', ['engine' => 'opensearch', 'host' => 'http://127.0.0.1:1', 'index' => self::INDEX], nonce: true));

        self::assertFalse($refused['works']);
        self::assertSame([self::host(), true], [$refused['host'], $refused['ready']], 'the working server stays');
    }

    public function testAnIndexHoldingAnotherShopsProductsIsRefused(): void
    {
        $this->actAs('administrator');
        $own = $this->data($this->request('GET', '/search-engine'));
        self::assertTrue($own['ready'], 'the shop prepared the index for itself');
        $mapping = json_decode((string) wp_remote_retrieve_body(wp_remote_get(self::host() . '/' . self::INDEX . '/_mapping')), true);
        self::assertIsArray($mapping);
        self::assertSame(OpenSearchServer::owner(), OpenSearchServer::ownerOf($mapping, self::INDEX), 'the index remembers whose it is');

        // Another shop (another database or prefix) prepared this index before.
        wp_remote_request(self::host() . '/' . self::INDEX . '/_mapping', ['method' => 'PUT', 'headers' => ['Content-Type' => 'application/json'], 'body' => (string) wp_json_encode(['_meta' => ['orillacart' => 'other:wp_7_orillacart_']])]);
        $refused = $this->data($this->request('PUT', '/search-engine', ['engine' => 'opensearch', 'host' => self::host(), 'index' => self::INDEX], nonce: true));

        self::assertFalse($refused['works']);
        self::assertIsString($refused['message']);
        self::assertStringContainsString('another shop', $refused['message']);
    }

    public function testWordsAreFoundByTheirStemInTheirLanguage(): void
    {
        $search = self::kernel()->container()->get(ShopSearch::class);
        self::assertInstanceOf(ShopSearch::class, $search);

        // "read" is three letters from "reading", more than a typo forgiven; English reads both as "read".
        self::assertCount(1, $search->search(new SearchQuery(text: 'read'))->ids, 'the site\'s language, English');
        self::assertCount(1, $search->search(new SearchQuery(text: 'reads'))->ids);
    }

    protected function work(): void
    {
        parent::work();
        // OpenSearch shows new documents after its refresh interval; tests look at once.
        wp_remote_post(self::host() . '/' . self::INDEX . '/_refresh');
    }

    private function deleteIndex(): void
    {
        wp_remote_request(self::host() . '/' . self::INDEX, ['method' => 'DELETE']);
    }

    private static function host(): string
    {
        $host = getenv('ORILLACART_OPENSEARCH');

        return \is_string($host) ? rtrim($host, '/') : '';
    }
}
