<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\OpenSearch;

use OrillaCart\Search\Application\Dto\DocumentOption;
use OrillaCart\Search\Application\Dto\DocumentTerm;
use OrillaCart\Search\Application\Dto\DocumentVariant;
use OrillaCart\Search\Application\Dto\SearchDocument;
use OrillaCart\Search\Application\SearchIndex;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\Facets;

/**
 * Keeps documents in the shop's OpenSearch index. Each variant carries its own options and the whole
 * product's, as "attr:size␟m", so one nested query asks for a variant that has all the chosen ones.
 */
final readonly class OpenSearchIndex implements SearchIndex
{
    /** Between a facet and its value in a variant's options: neither can hold it. */
    public const string SEPARATOR = "\u{1F}";

    public function __construct(
        private OpenSearchClient $client,
        private EngineSettingsRepository $settings,
    ) {}

    public function put(SearchDocument $document): void
    {
        $settings = $this->settings->load();
        [$priceMin, $priceMax] = $document->priceRange();
        $productOptions = self::options($document->options);
        $this->client->request($settings, 'PUT', "/{$settings->index}/_doc/{$document->productId}", [
            'product_id' => $document->productId,
            'title' => $document->title,
            'text' => $document->text,
            'skus' => $document->skus,
            'categories' => array_map(static fn(DocumentTerm $term): string => (string) $term->id, $document->categories),
            'brands' => array_map(static fn(DocumentTerm $term): string => (string) $term->id, $document->brands),
            'variants' => array_map(static fn(DocumentVariant $variant): array => [
                'id' => $variant->id,
                'price' => $variant->price === null ? null : (float) $variant->price,
                'in_stock' => $variant->inStock,
                'options' => array_values(array_unique([...$productOptions, ...self::options($variant->options)])),
            ], $document->variants),
            'price_min' => $priceMin === null ? null : (float) $priceMin,
            'price_max' => $priceMax === null ? null : (float) $priceMax,
            'in_stock' => $document->inStock(),
            'featured' => $document->featured,
            'on_sale' => $document->onSale,
            'menu_order' => $document->menuOrder,
            'published_at' => $document->publishedAt->format(\DATE_ATOM),
        ]);
    }

    public function remove(array $productIds): void
    {
        $settings = $this->settings->load();
        foreach ($productIds as $productId) {
            $this->client->request($settings, 'DELETE', "/{$settings->index}/_doc/{$productId}", missingOk: true);
        }
    }

    /**
     * @param list<DocumentOption> $options
     *
     * @return list<string>
     */
    private static function options(array $options): array
    {
        return array_map(static fn(DocumentOption $option): string => Facets::attribute($option->attribute) . self::SEPARATOR . Facets::value($option->name), $options);
    }
}
