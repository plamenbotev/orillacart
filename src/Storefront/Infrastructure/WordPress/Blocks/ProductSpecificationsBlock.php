<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Application\ProductSpecifications;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;

/**
 * A table of what the product is: each attribute the shop lists in the specifications with its options, in the
 * shopper's language, then its weight and size in the shop's units, each part unless turned off. Nothing for a
 * product with none of them.
 */
final readonly class ProductSpecificationsBlock implements BlockDefinition
{
    public function __construct(
        private ProductParts $parts,
        private ProductSpecifications $specifications,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/product-specifications';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);
        $specifications = $id === null || $this->parts->offer($id) === null ? null : $this->specifications->of($id);
        if ($id === null || $specifications === null) {
            return '';
        }
        $rows = [];
        if (($attributes['showAttributes'] ?? true) !== false) {
            foreach ($specifications->attributes as $attribute) {
                $rows[] = ['name' => $attribute['name'], 'value' => implode(', ', $attribute['values'])];
            }
        }
        if (($attributes['showWeight'] ?? true) !== false && $specifications->weight !== null) {
            $rows[] = ['name' => __('Weight', 'orillacart'), 'value' => self::number($specifications->weight) . ' ' . $specifications->weightUnit];
        }
        $size = [$specifications->length, $specifications->width, $specifications->height];
        if (($attributes['showDimensions'] ?? true) !== false && !\in_array(null, $size, true)) {
            /* translators: 1: length, 2: width, 3: height, 4: their unit, such as cm */
            $value = \sprintf(__('%1$s × %2$s × %3$s %4$s', 'orillacart'), ...[...array_map(self::number(...), $size), $specifications->dimensionUnit]);
            $rows[] = ['name' => __('Dimensions', 'orillacart'), 'value' => $value];
        }
        if ($rows === []) {
            return '';
        }

        return \sprintf('<div class="orillacart-product-specifications" data-orillacart-for="%d">%s</div>', $id, $this->templates->render('storefront/_product_specifications.html.twig', [
            'rows' => $rows,
            'title' => ($attributes['showTitle'] ?? true) !== false ? __('Specifications', 'orillacart') : '',
        ]));
    }

    /** A measure as the shopper's language writes numbers, with the decimals it has: "1,5". */
    private static function number(string $value): string
    {
        $fraction = explode('.', $value, 2)[1] ?? '';
        $decimals = \strlen(rtrim($fraction, '0'));

        return number_format_i18n((float) $value, $decimals);
    }
}
