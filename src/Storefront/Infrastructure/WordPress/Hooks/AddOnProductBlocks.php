<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Extension\Storefront\ProductBlock;
use OrillaCart\Extension\Storefront\ShownProduct;
use OrillaCart\Reviews\Application\Dto\RatingView;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;
use Psr\Log\LoggerInterface;

/**
 * Add-ons' product blocks (extension API 16): registered as "orillacart-addon/{id}" beside the shop's own, with
 * the product they render for (the page's, the card's, or their Product setting's) as a ShownProduct, and in the
 * editor with the shop's preview of the product blocks; the script and styles are the shop's blocks' (block.json)
 * handles. An add-on's failure shows nothing and is logged. Registered on wp_loaded, not init: reading the
 * registry fixes what add-ons register, so add-ons may hook on until init is over; blocks registered then are in
 * before any page, REST request or editor needs them.
 */
#[AsHook('wp_loaded', method: 'register')]
#[AsHook('enqueue_block_editor_assets', method: 'editor')]
final readonly class AddOnProductBlocks
{
    public const string NAMESPACE = 'orillacart-addon';

    public function __construct(
        private InstalledExtensions $extensions,
        private ProductParts $parts,
        private LoggerInterface $logger,
    ) {}

    public static function name(ProductBlock $block): string
    {
        return self::NAMESPACE . '/' . str_replace('_', '-', $block->id());
    }

    public function register(): void
    {
        foreach ($this->extensions->productBlocks() as $block) {
            register_block_type(self::name($block), [
                'api_version' => '3',
                'title' => $block->title(),
                'description' => $block->description(),
                'category' => 'theme',
                'icon' => 'admin-plugins',
                'attributes' => ['productId' => ['type' => 'integer', 'default' => 0], 'preview' => ['type' => 'boolean', 'default' => false]],
                'uses_context' => ['postId', 'postType'],
                'supports' => ['html' => false, 'spacing' => ['margin' => true, 'padding' => true]],
                'editor_script_handles' => ['orillacart-blocks'],
                'style_handles' => ['orillacart-storefront'],
                'render_callback' => fn(array $attributes, string $content = '', ?\WP_Block $wpBlock = null): string => $this->render($block, $attributes, BlockContext::of($content, $wpBlock)),
            ]);
        }
    }

    /** The editor gives each the shop's product block editing (assets/admin/blocks/product-blocks.tsx). */
    public function editor(): void
    {
        $names = array_map(self::name(...), $this->extensions->productBlocks());
        if ($names !== []) {
            wp_add_inline_script('orillacart-blocks', 'window.orillacartAddOnBlocks = ' . wp_json_encode($names) . ';', 'before');
        }
    }

    /**
     * @param array<mixed> $attributes
     */
    private function render(ProductBlock $block, array $attributes, BlockContext $context): string
    {
        $attributes = array_filter($attributes, \is_string(...), ARRAY_FILTER_USE_KEY);
        $id = ProductForBlock::id($attributes, $context);
        $card = $id === null ? null : $this->parts->card($id);
        $offer = $card['offer'] ?? null;
        if (!$offer instanceof ProductOffer) {
            return '';
        }
        $rating = $card['rating'] ?? null;
        try {
            $html = $block->render(new ShownProduct(
                $offer->id,
                $offer->name,
                \is_string($card['url'] ?? null) ? $card['url'] : '',
                $offer->sku,
                $offer->price,
                $offer->regularPrice,
                $offer->forSale,
                $offer->available,
                $rating instanceof RatingView ? $rating->average : null,
                $rating instanceof RatingView ? $rating->count : 0,
            ), $attributes);
        } catch (\Throwable $e) {
            $this->logger->error('The product block {block} failed: {message}', ['block' => self::name($block), 'message' => $e->getMessage()]);

            return '';
        }

        return $html === '' ? '' : \sprintf('<div %s>%s</div>', get_block_wrapper_attributes(), $html);
    }
}
