<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\OpenSearch;

use OrillaCart\Search\Application\SearchServer;
use OrillaCart\Search\Domain\EngineSettings;

/**
 * Prepares the shop's OpenSearch index: one document per product, its variants nested, so options, price and
 * stock hold for one variant together, as with the database engine.
 */
final readonly class OpenSearchServer implements SearchServer
{
    public function __construct(private OpenSearchClient $client) {}

    public function prepare(EngineSettings $settings): ?string
    {
        if (preg_match('#^https?://#', $settings->host) !== 1) {
            return 'The address of OpenSearch starts with http:// or https://.';
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $settings->index) !== 1) {
            return 'An index name is lowercase letters, digits, dashes and underscores.';
        }
        try {
            $this->client->request($settings, 'GET', '/');
            if ($this->client->request($settings, 'GET', "/{$settings->index}", missingOk: true) === []) {
                $this->client->request($settings, 'PUT', "/{$settings->index}", self::definition());
            } else {
                // An index made by an earlier version gets the fields added since; existing fields stay as they are.
                $this->client->request($settings, 'PUT', "/{$settings->index}/_mapping", ['properties' => self::properties()]);
            }
        } catch (OpenSearchUnavailable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'settings' => [
                'analysis' => [
                    'normalizer' => ['folded' => ['type' => 'custom', 'filter' => ['lowercase', 'asciifolding']]],
                    // English stopwords dropped, as the database engine drops InnoDB's: "the lamp" finds lamps.
                    'analyzer' => ['folded' => ['type' => 'custom', 'tokenizer' => 'standard', 'filter' => ['lowercase', 'asciifolding', 'stop']]],
                ],
            ],
            'mappings' => [
                'dynamic' => 'strict',
                'properties' => self::properties(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function properties(): array
    {
        $keyword = ['type' => 'keyword'];

        return [
            'product_id' => ['type' => 'integer'],
            'title' => ['type' => 'text', 'analyzer' => 'folded', 'fields' => ['sort' => ['type' => 'keyword', 'normalizer' => 'folded']]],
            'text' => ['type' => 'text', 'analyzer' => 'folded'],
            'skus' => ['type' => 'keyword', 'normalizer' => 'folded'],
            'categories' => $keyword,
            'brands' => $keyword,
            'tags' => $keyword,
            'variants' => [
                'type' => 'nested',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'price' => ['type' => 'double'],
                    'in_stock' => ['type' => 'boolean'],
                    'options' => $keyword,
                ],
            ],
            'price_min' => ['type' => 'double'],
            'price_max' => ['type' => 'double'],
            'in_stock' => ['type' => 'boolean'],
            'featured' => ['type' => 'boolean'],
            'on_sale' => ['type' => 'boolean'],
            'menu_order' => ['type' => 'integer'],
            'published_at' => ['type' => 'date'],
        ];
    }
}
