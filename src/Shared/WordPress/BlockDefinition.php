<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A block editors put on pages, which the server renders: the cart, the checkout. Put the class in the owning
 * area's Infrastructure/WordPress/Blocks folder and what the editor shows and stores in blocks/<name>/block.json
 * ("orillacart/cart" in blocks/cart); the BlockRegistrar registers it, and builds it only when a page shows the
 * block. The editor shows a placeholder for it (assets/admin/blocks).
 */
#[AutoconfigureTag(self::TAG)]
interface BlockDefinition
{
    public const string TAG = 'orillacart.block';

    /** "orillacart/cart" */
    public static function name(): string;

    /**
     * The block's HTML; the storefront script and styles are loaded with it.
     *
     * @param array<string, mixed> $attributes
     */
    public function render(array $attributes): string;
}
