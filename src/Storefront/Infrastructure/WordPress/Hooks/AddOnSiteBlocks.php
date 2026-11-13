<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Storefront\BlockPlace;
use OrillaCart\Extension\Storefront\PlacedSiteBlock;
use OrillaCart\Extension\Storefront\SiteBlock;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Shared\WordPress\AsHook;
use Psr\Log\LoggerInterface;

/**
 * Add-ons' blocks for the whole site (extension API 24): registered as "orillacart-addon/{id}", each setting an
 * attribute the owner fills in the block's sidebar (assets/admin/blocks/site-blocks.tsx), rendered on the server
 * with what was set, checked against the settings: a text up to 2000 characters, a number, on or off, one of the
 * choices; and a block's own kinds (API 28): an image of the media library, an address, a colour, a published
 * product, a product category, formatted text cleaned as a post's, a list of items of these. A PlacedSiteBlock may
 * hold other blocks and is told where it is; one with an editor() edits its settings in a frame of its own. An
 * add-on's failure shows nothing and is logged. Registered on wp_loaded, as the product blocks are.
 */
#[AsHook('wp_loaded', method: 'register')]
#[AsHook('enqueue_block_editor_assets', method: 'editor')]
final readonly class AddOnSiteBlocks
{
    private const int MOST_TEXT = 2000;
    private const int MOST_RICH_TEXT = 20000;
    private const int MOST_ITEMS = 50;

    public function __construct(private InstalledExtensions $extensions, private LoggerInterface $logger) {}

    public static function name(SiteBlock $block): string
    {
        return AddOnProductBlocks::NAMESPACE . '/' . str_replace('_', '-', $block->id());
    }

    public function register(): void
    {
        foreach ($this->extensions->siteBlocks() as $block) {
            $attributes = [];
            foreach (self::settingsOf($block) as $setting) {
                $attributes[$setting->key] = ['type' => self::attributeType($setting)];
            }
            register_block_type(self::name($block), [
                'api_version' => '3',
                'title' => $block->title(),
                'description' => $block->description(),
                'category' => 'widgets',
                'icon' => 'admin-plugins',
                'attributes' => $attributes,
                'uses_context' => ['postId'],
                'supports' => ['html' => false, 'align' => ['wide', 'full'], 'spacing' => ['margin' => true, 'padding' => true]],
                'editor_script_handles' => ['orillacart-blocks'],
                'style_handles' => ['orillacart-storefront'],
                'render_callback' => fn(array $attributes, string $content = '', ?\WP_Block $wpBlock = null): string => $this->render($block, $attributes, $content, $wpBlock),
            ]);
        }
    }

    /** What the editor builds each block's sidebar from. */
    public function editor(): void
    {
        $blocks = [];
        foreach ($this->extensions->siteBlocks() as $block) {
            $placed = $block instanceof PlacedSiteBlock ? $block : null;
            $editor = null;
            try {
                $editor = $placed?->editor();
            } catch (\Throwable $e) {
                $this->logger->error('The site block {block}\'s editing screen failed: {message}', ['block' => self::name($block), 'message' => $e->getMessage()]);
            }
            $blocks[] = [
                'name' => self::name($block),
                'settings' => array_map(self::definition(...), self::settingsOf($block)),
                'inner' => $placed?->inner() ?? false,
                // Its own editing screen, which runs in a sandboxed frame (design 73), and whose routes it may call.
                'editor' => $editor?->document,
                'extension' => $editor === null ? '' : $editor->extension,
            ];
        }
        if ($blocks !== []) {
            wp_add_inline_script('orillacart-blocks', 'window.orillacartSiteBlocks = ' . wp_json_encode($blocks) . ';', 'before');
        }
    }

    /**
     * A setting as the editor's sidebar reads it.
     *
     * @return array<string, mixed>
     */
    private static function definition(Setting $setting): array
    {
        return ['key' => $setting->key, 'label' => $setting->label, 'type' => $setting->type, 'help' => $setting->help, 'choices' => $setting->choices, 'fields' => array_map(self::definition(...), $setting->fields)];
    }

    /** The block attribute a setting is kept in. */
    private static function attributeType(Setting $setting): string
    {
        return match ($setting->type) {
            Setting::BOOLEAN => 'boolean',
            Setting::NUMBER => 'number',
            Setting::IMAGE, Setting::PRODUCT, Setting::CATEGORY => 'integer',
            Setting::LIST => 'array',
            default => 'string',
        };
    }

    /**
     * The settings a block may have: a secret is not a block's, and a key must be one.
     *
     * @return list<Setting>
     */
    private static function settingsOf(SiteBlock $block): array
    {
        return array_values(array_filter($block->settings(), static fn(Setting $setting): bool => $setting->type !== Setting::SECRET && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $setting->key) === 1));
    }

    /**
     * What the owner set, as the settings take it: anything else is left out.
     *
     * @param array<mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public static function values(SiteBlock $block, array $attributes): array
    {
        return self::checked(self::settingsOf($block), $attributes);
    }

    /**
     * @param list<Setting> $settings
     * @param array<mixed>  $attributes
     *
     * @return array<string, mixed>
     */
    private static function checked(array $settings, array $attributes): array
    {
        $values = [];
        foreach ($settings as $setting) {
            $checked = self::value($setting, $attributes[$setting->key] ?? null);
            if ($checked !== null) {
                $values[$setting->key] = $checked;
            }
        }

        return $values;
    }

    private static function value(Setting $setting, mixed $value): mixed
    {
        return match ($setting->type) {
            Setting::BOOLEAN => \is_bool($value) ? $value : null,
            Setting::NUMBER => \is_int($value) || \is_float($value) ? $value : (\is_string($value) && is_numeric($value) ? $value + 0 : null),
            Setting::CHOICE => \is_string($value) && \array_key_exists($value, $setting->choices) ? $value : null,
            Setting::IMAGE => self::image($value),
            Setting::LINK => self::link($value),
            Setting::COLOR => \is_string($value) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1 ? strtolower($value) : null,
            Setting::PRODUCT => self::product(self::id($value)),
            Setting::CATEGORY => self::category(self::id($value)),
            Setting::RICH_TEXT => \is_string($value) && $value !== '' ? wp_kses_post(mb_substr($value, 0, self::MOST_RICH_TEXT)) : null,
            Setting::LIST => \is_array($value) ? array_values(array_map(static fn(mixed $item): array => self::checked($setting->fields, \is_array($item) ? $item : []), \array_slice(array_values($value), 0, self::MOST_ITEMS))) : null,
            default => \is_string($value) ? mb_substr($value, 0, self::MOST_TEXT) : null,
        };
    }

    /** A published product's id. */
    private static function product(?int $id): ?int
    {
        return $id !== null && get_post_type($id) === ProductPostType::NAME && get_post_status($id) === 'publish' ? $id : null;
    }

    /** A product category's id. */
    private static function category(?int $id): ?int
    {
        return $id !== null && term_exists($id, ProductCategoryTaxonomy::NAME) !== null ? $id : null;
    }

    private static function id(mixed $value): ?int
    {
        $id = \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : 0);

        return $id > 0 ? $id : null;
    }

    /**
     * An image of the media library as a block gets it.
     *
     * @return array{id: int, url: string, alt: string, width: int|null, height: int|null}|null
     */
    private static function image(mixed $value): ?array
    {
        $id = self::id($value);
        if ($id === null || !wp_attachment_is_image($id)) {
            return null;
        }
        $source = wp_get_attachment_image_src($id, 'full');
        if ($source === false) {
            return null;
        }
        $alt = get_post_meta($id, '_wp_attachment_image_alt', true);

        return ['id' => $id, 'url' => $source[0], 'alt' => \is_string($alt) ? $alt : '', 'width' => $source[1] > 0 ? $source[1] : null, 'height' => $source[2] > 0 ? $source[2] : null];
    }

    /** An address a link may go to: http(s), mailto:, tel:, a path or a #fragment. */
    private static function link(mixed $value): ?string
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim(mb_substr($value, 0, self::MOST_TEXT));
        if (str_starts_with($value, '#') || (str_starts_with($value, '/') && !str_starts_with($value, '//'))) {
            return esc_url_raw($value) === '' ? null : $value;
        }
        $clean = esc_url_raw($value, ['http', 'https', 'mailto', 'tel']);

        return $clean === '' ? null : $clean;
    }

    /**
     * @param array<mixed> $attributes
     */
    private function render(SiteBlock $block, array $attributes, string $content, ?\WP_Block $wpBlock): string
    {
        try {
            $values = self::values($block, $attributes);
            if ($block instanceof PlacedSiteBlock) {
                $postId = $wpBlock?->context['postId'] ?? get_the_ID();
                // The editor's preview asks the block renderer, in the edit context.
                $preview = \defined('REST_REQUEST') && REST_REQUEST && ($_GET['context'] ?? '') === 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only
                $html = $block->renderIn($values, new BlockPlace(\is_int($postId) && $postId > 0 ? $postId : null, $preview, $block->inner() ? $content : ''));
            } else {
                $html = $block->render($values);
            }
        } catch (\Throwable $e) {
            $this->logger->error('The site block {block} failed: {message}', ['block' => self::name($block), 'message' => $e->getMessage()]);

            return '';
        }

        return $html === '' ? '' : \sprintf('<div %s>%s</div>', get_block_wrapper_attributes(), $html);
    }
}
