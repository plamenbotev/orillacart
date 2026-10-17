<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\VariationPostType;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The images a product or variation shows besides its featured image, in order: media library ids in post
 * meta, which the editor sets through the REST API.
 */
#[AsHook('init', priority: 20)]
final readonly class ProductGallery
{
    public const string META = 'orillacart_gallery';

    /**
     * @return list<int> the gallery's images, in order
     */
    public static function of(int $postId): array
    {
        return self::ids(get_post_meta($postId, self::META, true));
    }

    public function __invoke(): void
    {
        foreach ([ProductPostType::NAME, VariationPostType::NAME] as $postType) {
            register_post_meta($postType, self::META, [
                'type' => 'array',
                'single' => true,
                'default' => [],
                'show_in_rest' => ['schema' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]]],
                'auth_callback' => static fn(bool $allowed, string $key, int $postId): bool => current_user_can('edit_post', $postId),
                'sanitize_callback' => self::ids(...),
            ]);
        }
    }

    /**
     * @return list<int> the positive whole numbers in the value, in order
     */
    private static function ids(mixed $value): array
    {
        $ids = [];
        foreach (\is_array($value) ? $value : [] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
