<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Search\Application\Dto\DocumentTerm;
use OrillaCart\Search\Application\Dto\ProductText;
use OrillaCart\Search\Application\ProductTexts;

/**
 * Reads products' posts and terms. The content is taken as stored, without the_content's filters: those render
 * blocks and shortcodes for a page, which a search index does not need.
 */
final readonly class WordPressProductTexts implements ProductTexts
{
    public function describe(int $productId): ?ProductText
    {
        $post = get_post($productId);
        if (!$post instanceof \WP_Post || $post->post_type !== ProductPostType::NAME || $post->post_status !== 'publish') {
            return null;
        }

        $published = $post->post_date_gmt !== '0000-00-00 00:00:00' ? $post->post_date_gmt : $post->post_date;

        return new ProductText(
            self::plain($post->post_title),
            self::plain($post->post_excerpt . ' ' . strip_shortcodes($post->post_content)),
            $post->menu_order,
            new \DateTimeImmutable($published, new \DateTimeZone('UTC')),
            self::terms($productId, ProductCategoryTaxonomy::NAME),
            self::terms($productId, ProductBrandTaxonomy::NAME),
        );
    }

    /**
     * The product's terms and every term above them, each once.
     *
     * @return list<DocumentTerm>
     */
    private static function terms(int $productId, string $taxonomy): array
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
                    $found[$id] = new DocumentTerm($id, self::plain($named->name));
                }
            }
        }

        return array_values($found);
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
