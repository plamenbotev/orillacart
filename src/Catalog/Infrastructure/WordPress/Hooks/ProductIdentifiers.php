<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * What a product is known by outside the shop, for search engines and marketplaces: its barcode (GTIN: EAN,
 * UPC or ISBN, 8 to 14 digits) and the maker's part number (MPN). Post meta the editor sets through the REST API.
 */
#[AsHook('init', priority: 20)]
final readonly class ProductIdentifiers
{
    public const string GTIN = 'orillacart_gtin';
    public const string MPN = 'orillacart_mpn';

    public static function gtinOf(int $productId): string
    {
        $gtin = get_post_meta($productId, self::GTIN, true);

        return \is_string($gtin) ? $gtin : '';
    }

    public static function mpnOf(int $productId): string
    {
        $mpn = get_post_meta($productId, self::MPN, true);

        return \is_string($mpn) ? $mpn : '';
    }

    public function __invoke(): void
    {
        $edit = static fn(bool $allowed, string $key, int $postId): bool => current_user_can('edit_post', $postId);
        register_post_meta(ProductPostType::NAME, self::GTIN, [
            'type' => 'string',
            'single' => true,
            'default' => '',
            'show_in_rest' => ['schema' => ['type' => 'string', 'pattern' => '^([0-9]{8}|[0-9]{12,14})?$']],
            'auth_callback' => $edit,
            'sanitize_callback' => static fn(mixed $value): string => \is_string($value) ? (string) preg_replace('/\D/', '', $value) : '',
        ]);
        register_post_meta(ProductPostType::NAME, self::MPN, [
            'type' => 'string',
            'single' => true,
            'default' => '',
            'show_in_rest' => ['schema' => ['type' => 'string', 'maxLength' => 70]],
            'auth_callback' => $edit,
            'sanitize_callback' => static fn(mixed $value): string => \is_string($value) ? mb_substr(sanitize_text_field($value), 0, 70) : '',
        ]);
    }
}
