<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Search\Application\Dto\DocumentTerm;
use OrillaCart\Search\Application\Dto\DocumentText;
use OrillaCart\Search\Application\Dto\ProductText;
use OrillaCart\Search\Application\ProductTexts;
use OrillaCart\Shared\I18n\ContentLanguages;

/**
 * Reads products' posts and terms (categories, brands, tags). The content is taken as stored, without the_content's filters: those render
 * blocks and shortcodes for a page, which a search index does not need. On a site in several languages also the words
 * of each product's published translations, and its terms' names in the other languages (design 51).
 */
final readonly class WordPressProductTexts implements ProductTexts
{
    public function __construct(private ContentLanguages $languages) {}

    public function describe(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $locales = [];
        $translations = [];
        if ($this->languages->multilingual()) {
            foreach ($this->languages->languages() as $language) {
                $locales[$language->code] = $language->locale;
            }
            foreach ($productIds as $productId) {
                $translations[$productId] = array_filter($this->languages->postTranslations($productId), static fn(int $id): bool => $id !== $productId);
            }
        }
        // The posts and their terms in a few queries, not a few each.
        $postIds = array_values(array_unique([...$productIds, ...array_merge([], ...array_map(array_values(...), array_values($translations)))]));
        _prime_post_caches($postIds, false, false);
        update_object_term_cache($postIds, ProductPostType::NAME);
        $texts = [];
        foreach ($productIds as $productId) {
            $text = $this->text($productId, $translations[$productId] ?? [], $locales);
            if ($text !== null) {
                $texts[$productId] = $text;
            }
        }

        return $texts;
    }

    /**
     * @param array<string, int>    $translations the other posts of its translations, by language code
     * @param array<string, string> $locales      the site's locales by language code; none on a site in one
     */
    private function text(int $productId, array $translations, array $locales): ?ProductText
    {
        $post = self::published($productId);
        if ($post === null) {
            return null;
        }

        $published = $post->post_date_gmt !== '0000-00-00 00:00:00' ? $post->post_date_gmt : $post->post_date;
        [$title, $text, $tags] = self::words($post, $locales);
        $code = $locales === [] ? null : $this->languages->ofPost($productId);
        $texts = [];
        foreach ($translations as $translationCode => $translationId) {
            $translation = isset($locales[$translationCode]) ? self::published($translationId) : null;
            if ($translation !== null) {
                [$translatedTitle, $translatedText] = self::words($translation, []);
                $texts[] = new DocumentText($locales[$translationCode], $translatedTitle, $translatedText);
            }
        }

        // The tags' names are words shoppers find the product by.
        return new ProductText(
            $title,
            $text,
            $post->menu_order,
            new \DateTimeImmutable($published, new \DateTimeZone('UTC')),
            $this->terms($productId, ProductCategoryTaxonomy::NAME, $locales),
            $this->terms($productId, ProductBrandTaxonomy::NAME, $locales),
            $tags,
            // A post the plugin gave no language is in the default one, as the plugins ask of such posts.
            $locales === [] ? null : ($locales[$code ?? ''] ?? $this->languages->default()?->locale),
            $texts,
        );
    }

    private static function published(int $postId): ?\WP_Post
    {
        $post = get_post($postId);

        return $post instanceof \WP_Post && $post->post_type === ProductPostType::NAME && $post->post_status === 'publish' ? $post : null;
    }

    /**
     * A post's title, and its excerpt, content and tags' names as text.
     *
     * @param array<string, string> $locales for the tags' names in other languages
     *
     * @return array{string, string, list<DocumentTerm>}
     */
    private function words(\WP_Post $post, array $locales): array
    {
        $tags = $this->terms($post->ID, ProductTagTaxonomy::NAME, $locales);

        return [
            self::plain($post->post_title),
            self::plain(implode(' ', [$post->post_excerpt, strip_shortcodes($post->post_content), ...array_map(static fn(DocumentTerm $tag): string => $tag->name, $tags)])),
            $tags,
        ];
    }

    /**
     * The post's terms and every term above them, each once, with their names in the other languages.
     *
     * @param array<string, string> $locales by language code
     *
     * @return list<DocumentTerm>
     */
    private function terms(int $productId, string $taxonomy, array $locales): array
    {
        $terms = get_the_terms($productId, $taxonomy);
        if (!\is_array($terms)) {
            return [];
        }
        $found = [];
        foreach ($terms as $term) {
            foreach ([$term->term_id, ...get_ancestors($term->term_id, $taxonomy, 'taxonomy')] as $id) {
                if (isset($found[$id])) {
                    continue;
                }
                $named = $id === $term->term_id ? $term : get_term($id, $taxonomy);
                if ($named instanceof \WP_Term) {
                    $found[$id] = new DocumentTerm($id, self::plain($named->name), $this->labels($named, $locales));
                }
            }
        }

        return array_values($found);
    }

    /**
     * The term's names in the other languages, from its translations, where they differ from its own.
     *
     * @param array<string, string> $locales by language code
     *
     * @return array<string, string> by locale
     */
    private function labels(\WP_Term $term, array $locales): array
    {
        if ($locales === []) {
            return [];
        }
        $name = self::plain($term->name);
        $labels = [];
        foreach ($this->languages->termTranslations($term->term_id, $term->taxonomy) as $code => $translationId) {
            $translation = $translationId === $term->term_id || !isset($locales[$code]) ? null : get_term($translationId, $term->taxonomy);
            if ($translation instanceof \WP_Term && self::plain($translation->name) !== $name) {
                $labels[$locales[$code]] = self::plain($translation->name);
            }
        }

        return $labels;
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
