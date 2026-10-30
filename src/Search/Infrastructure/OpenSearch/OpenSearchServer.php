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
        $owner = self::owner();
        try {
            $this->client->request($settings, 'GET', '/');
            $existing = $this->client->request($settings, 'GET', "/{$settings->index}", missingOk: true);
            if ($existing === []) {
                $this->client->request($settings, 'PUT', "/{$settings->index}", self::definition($owner));
            } else {
                // The index remembers whose it is: documents are keyed by product id, so two shops in one index
                // would overwrite and prune each other's.
                $meta = self::ownerOf($existing, $settings->index);
                if ($meta !== null && $meta !== $owner) {
                    return \sprintf('The index "%s" holds another shop\'s products. Give this shop an index of its own.', $settings->index);
                }
                // An index made by an earlier version gets the fields added since; existing fields stay as they are.
                $this->client->request($settings, 'PUT', "/{$settings->index}/_mapping", ['_meta' => ['orillacart' => $owner], 'properties' => self::properties()]);
            }
        } catch (OpenSearchUnavailable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** What tells this shop's tables from every other's that may reach the same server: the database and prefix. */
    public static function owner(): string
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        $database = \defined('DB_NAME') ? \constant('DB_NAME') : '';

        return (\is_string($database) ? $database : '') . ':' . ($wpdb instanceof \wpdb ? $wpdb->prefix : '');
    }

    /**
     * The shop an index answer (GET /{index}) says the index belongs to; null for one made before owners were kept.
     *
     * @param array<mixed> $answer
     */
    public static function ownerOf(array $answer, string $index): ?string
    {
        $mappings = \is_array($answer[$index] ?? null) ? ($answer[$index]['mappings'] ?? null) : null;
        $meta = \is_array($mappings) ? ($mappings['_meta'] ?? null) : null;
        $owner = \is_array($meta) ? ($meta['orillacart'] ?? null) : null;

        return \is_string($owner) ? $owner : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(string $owner = ''): array
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
                '_meta' => ['orillacart' => $owner],
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
            'locale' => $keyword,
            // A translation's words, beside the product's own document, which lists the languages it has them in.
            'translation' => ['type' => 'boolean'],
            'translated' => $keyword,
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
            // The words as their language's analyzer reads them, under its name: only a document in it has them.
            'words' => ['properties' => array_combine(OpenSearchLanguages::all(), array_map(static fn(string $analyzer): array => ['properties' => [
                'title' => ['type' => 'text', 'analyzer' => $analyzer],
                'text' => ['type' => 'text', 'analyzer' => $analyzer],
            ]], OpenSearchLanguages::all()))],
        ];
    }
}
