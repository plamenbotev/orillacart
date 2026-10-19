<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * Switching search engines, on the wp-env site: an address OpenSearch could not be at is refused before it is asked.
 */
final class SearchEngineRestTest extends RestTestCase
{
    public function testAnAddressThatIsNotOneOrCarriesCredentialsIsRefused(): void
    {
        $this->actAs('administrator');
        $before = $this->data($this->request('GET', '/search-engine'));

        foreach ([
            'search.example.com:9200' => 'Enter the address OpenSearch listens on',
            'ftp://search.example.com' => 'Enter the address OpenSearch listens on',
            'https://admin:secret@search.example.com:9200' => 'not in the address',
            'https://search.example.com:9200/?debug=1' => 'has no query',
        ] as $host => $message) {
            $refused = $this->data($this->request('PUT', '/search-engine', ['engine' => 'opensearch', 'host' => $host], nonce: true));
            self::assertFalse($refused['works'], $host);
            self::assertIsString($refused['message']);
            self::assertStringContainsString($message, $refused['message'], $host);
        }
        self::assertSame($before, $this->data($this->request('GET', '/search-engine')), 'nothing changed');
    }
}
