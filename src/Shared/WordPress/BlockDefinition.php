<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A block editors put on pages, which the server renders: the cart, the checkout. Put the class in the owning
 * area's Infrastructure/WordPress/Blocks folder; the BlockRegistrar registers it, and builds it only when a page
 * shows the block. The editor shows a placeholder for it (assets/admin/blocks).
 */
#[AutoconfigureTag(self::TAG)]
interface BlockDefinition
{
    public const string TAG = 'orillacart.block';

    /** "orillacart/cart" */
    public static function name(): string;

    /**
     * What the editor shows and stores.
     *
     * @return array{title: string, description: string, icon: string, keywords?: list<string>, attributes?: array<string, array{type: string, default?: mixed}>}
     */
    public static function metadata(): array;

    /**
     * The block's HTML; the storefront script and styles are loaded with it.
     *
     * @param array<string, mixed> $attributes
     */
    public function render(array $attributes): string;
}
