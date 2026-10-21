<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The products the shop puts beside a product: upsells, the better ones its page offers instead, and
 * cross-sells, those the cart offers with it. Product ids in post meta, in order, which the editor sets through
 * the REST API; a product that is not published when shown is left out then.
 */
#[AsHook('init', priority: 20)]
final readonly class LinkedProducts
{
    public const string UPSELLS = 'orillacart_upsells';
    public const string CROSS_SELLS = 'orillacart_cross_sells';
    public const int MOST = 20;

    /**
     * @return list<int>
     */
    public static function upsellsOf(int $productId): array
    {
        return self::ids(get_post_meta($productId, self::UPSELLS, true), $productId);
    }

    /**
     * @return list<int>
     */
    public static function crossSellsOf(int $productId): array
    {
        return self::ids(get_post_meta($productId, self::CROSS_SELLS, true), $productId);
    }

    public function __invoke(): void
    {
        foreach ([self::UPSELLS, self::CROSS_SELLS] as $key) {
            register_post_meta(ProductPostType::NAME, $key, [
                'type' => 'array',
                'single' => true,
                'default' => [],
                'show_in_rest' => ['schema' => ['type' => 'array', 'maxItems' => self::MOST, 'items' => ['type' => 'integer', 'minimum' => 1]]],
                'auth_callback' => static fn(bool $allowed, string $metaKey, int $postId): bool => current_user_can('edit_post', $postId),
                'sanitize_callback' => static fn(mixed $value, string $metaKey, string $objectType): array => self::ids($value, 0),
            ]);
        }
    }

    /**
     * @return list<int> the product ids in the value, each once, in order, without the product itself
     */
    private static function ids(mixed $value, int $self): array
    {
        $ids = [];
        foreach (\is_array($value) ? $value : [] as $id) {
            if (is_numeric($id) && (int) $id > 0 && (int) $id !== $self) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return \array_slice(array_values($ids), 0, self::MOST);
    }
}
