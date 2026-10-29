<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\OpenSearch;

use OrillaCart\Search\Application\Dto\DocumentOption;
use OrillaCart\Search\Application\Dto\DocumentTerm;
use OrillaCart\Search\Application\Dto\DocumentText;
use OrillaCart\Search\Application\Dto\DocumentVariant;
use OrillaCart\Search\Application\Dto\SearchDocument;
use OrillaCart\Search\Application\SearchIndex;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\Facets;
use OrillaCart\Shared\I18n\ContentLanguages;

/**
 * Keeps documents in the shop's OpenSearch index. Each variant carries its own options and the whole
 * product's, as "attr:size␟m", so one nested query asks for a variant that has all the chosen ones. On a site in
 * several languages a product's translations are documents of their own ("12@de_DE"), with what the product is
 * filtered and sorted by; the product's lists the languages it has them in (design 51).
 */
final readonly class OpenSearchIndex implements SearchIndex
{
    /** Between a facet and its value in a variant's options: neither can hold it. */
    public const string SEPARATOR = "\u{1F}";

    public function __construct(
        private OpenSearchClient $client,
        private EngineSettingsRepository $settings,
        private ContentLanguages $languages,
    ) {}

    /**
     * One bulk request for the batch; when OpenSearch refuses any document, the batch fails, and the worker
     * builds its products one by one to find the one.
     */
    public function put(array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $settings = $this->settings->load();
        $lines = '';
        foreach ($documents as $document) {
            $source = self::source($document);
            $lines .= wp_json_encode(['index' => ['_index' => $settings->index, '_id' => (string) $document->productId]]) . "\n" . wp_json_encode($source) . "\n";
            foreach ($document->translations as $text) {
                $lines .= wp_json_encode(['index' => ['_index' => $settings->index, '_id' => self::translationId($document->productId, $text->locale)]]) . "\n" . wp_json_encode(self::translation($source, $text)) . "\n";
            }
            // A translation no longer published leaves.
            foreach (array_diff($this->locales(), $document->translatedInto()) as $locale) {
                $lines .= wp_json_encode(['delete' => ['_index' => $settings->index, '_id' => self::translationId($document->productId, $locale)]]) . "\n";
            }
        }
        $answer = $this->client->request($settings, 'POST', '/_bulk', $lines);
        if (($answer['errors'] ?? false) === true) {
            throw new OpenSearchUnavailable('OpenSearch refused a document of the batch: ' . self::firstError($answer));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function source(SearchDocument $document): array
    {
        [$priceMin, $priceMax] = $document->priceRange();
        $productOptions = self::options($document->options);

        return [
            'product_id' => $document->productId,
            'locale' => $document->locale ?? '',
            'translation' => false,
            'translated' => $document->translatedInto(),
            'title' => $document->title,
            'text' => $document->text,
            'skus' => $document->skus,
            'categories' => array_map(static fn(DocumentTerm $term): string => (string) $term->id, $document->categories),
            'brands' => array_map(static fn(DocumentTerm $term): string => (string) $term->id, $document->brands),
            'tags' => array_map(static fn(DocumentTerm $term): string => (string) $term->id, $document->tags),
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
        ];
    }

    /**
     * A translation's document: the product's, with the translation's words.
     *
     * @param array<string, mixed> $source the product's
     *
     * @return array<string, mixed>
     */
    private static function translation(array $source, DocumentText $text): array
    {
        return ['locale' => $text->locale, 'translation' => true, 'translated' => [], 'title' => $text->title, 'text' => $text->text] + $source;
    }

    private static function translationId(int $productId, string $locale): string
    {
        return $productId . '@' . $locale;
    }

    /**
     * @return list<string> the locales a product may have translations' documents in
     */
    private function locales(): array
    {
        return $this->languages->multilingual() ? array_map(static fn($language): string => $language->locale, $this->languages->languages()) : [];
    }

    /**
     * @param array<mixed> $answer
     */
    private static function firstError(array $answer, string $action = 'index'): string
    {
        foreach (\is_array($answer['items'] ?? null) ? $answer['items'] : [] as $item) {
            $reason = \is_array($item) && \is_array($item[$action] ?? null) && \is_array($item[$action]['error'] ?? null) ? ($item[$action]['error']['reason'] ?? null) : null;
            if (\is_string($reason)) {
                return $reason;
            }
        }

        return 'no reason given';
    }

    public function remove(array $productIds): void
    {
        $settings = $this->settings->load();
        if ($productIds === []) {
            return;
        }
        $lines = '';
        foreach ($productIds as $productId) {
            foreach ([(string) $productId, ...array_map(static fn(string $locale): string => self::translationId($productId, $locale), $this->locales())] as $id) {
                $lines .= wp_json_encode(['delete' => ['_index' => $settings->index, '_id' => $id]]) . "\n";
            }
        }
        $answer = $this->client->request($settings, 'POST', '/_bulk', $lines);
        // A document that was not there is gone all the same; anything else leaves a product shoppers can find.
        foreach (\is_array($answer['items'] ?? null) ? $answer['items'] : [] as $item) {
            $delete = \is_array($item) && \is_array($item['delete'] ?? null) ? $item['delete'] : [];
            if (isset($delete['error']) && ($delete['status'] ?? null) !== 404) {
                throw new OpenSearchUnavailable('OpenSearch did not remove a document: ' . self::firstError($answer, 'delete'));
            }
        }
    }

    /**
     * Pages through the index's products (not their translations) by id, a thousand at a time.
     */
    public function productIds(): array
    {
        $settings = $this->settings->load();
        $ids = [];
        $after = null;
        do {
            $answer = $this->client->request($settings, 'POST', '/' . rawurlencode($settings->index) . '/_search', (string) wp_json_encode(array_filter([
                '_source' => false,
                'size' => 1000,
                'query' => ['bool' => ['must_not' => [['term' => ['translation' => true]]]]],
                'sort' => [['product_id' => 'asc']],
                'search_after' => $after,
            ], static fn(mixed $value): bool => $value !== null)));
            $hits = \is_array($answer['hits'] ?? null) && \is_array($answer['hits']['hits'] ?? null) ? $answer['hits']['hits'] : [];
            foreach ($hits as $hit) {
                if (\is_array($hit) && is_numeric($hit['_id'] ?? null)) {
                    $ids[] = (int) $hit['_id'];
                    $after = \is_array($hit['sort'] ?? null) ? $hit['sort'] : null;
                }
            }
        } while (\count($hits) === 1000 && $after !== null);

        return $ids;
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
