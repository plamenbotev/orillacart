<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A product's translations are its text (design 51): they go to the trash with it, come back with it, and are
 * deleted with it for good, so no language keeps a page of a product that is gone. Only from the product down:
 * a translation trashed or deleted is that language's page alone.
 *
 * WordPress keeps posts without foreign keys, and Polylang and WPML keep their translation groups their own ways,
 * so this follows WordPress's actions. Each step stands on its own: a translation whose delete failed is left
 * without a product, shows no price and cannot be bought.
 */
#[AsHook('trashed_post', method: 'trashed')]
#[AsHook('untrashed_post', method: 'untrashed')]
// Before the product's details go at 10, and before Polylang and WPML forget the group.
#[AsHook('before_delete_post', method: 'deleting', priority: 5, arguments: 2)]
final readonly class TranslationsFollowTheProduct
{
    /** On a translation the product put in the trash: which product, to bring it back with. */
    public const string TRASHED_WITH = '_orillacart_trashed_with';

    public function __construct(private ProductTranslations $translations) {}

    public function trashed(int $postId): void
    {
        if (get_post_type($postId) !== ProductPostType::NAME) {
            return;
        }
        foreach ($this->translations->translationsOf($postId) as $translation) {
            if (!\in_array(get_post_status($translation), [false, 'trash'], true) && wp_trash_post($translation) instanceof \WP_Post) {
                update_post_meta($translation, self::TRASHED_WITH, $postId);
            }
        }
    }

    public function untrashed(int $postId): void
    {
        if (get_post_type($postId) !== ProductPostType::NAME) {
            return;
        }
        // Only those it took: a translation trashed on its own before stays where it is.
        foreach ($this->translations->translationsOf($postId) as $translation) {
            $with = get_post_meta($translation, self::TRASHED_WITH, true);
            if (is_numeric($with) && (int) $with === $postId) {
                delete_post_meta($translation, self::TRASHED_WITH);
                wp_untrash_post($translation);
            }
        }
    }

    public function deleting(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== ProductPostType::NAME) {
            return;
        }
        // Read before any is deleted: the group changes as they go.
        foreach ($this->translations->translationsOf($postId) as $translation) {
            if (get_post($translation) instanceof \WP_Post) {
                wp_delete_post($translation, true);
            }
        }
    }
}
