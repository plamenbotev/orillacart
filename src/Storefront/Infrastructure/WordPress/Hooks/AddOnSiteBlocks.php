<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Storefront\SiteBlock;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Shared\WordPress\AsHook;
use Psr\Log\LoggerInterface;

/**
 * Add-ons' blocks for the whole site (extension API 24): registered as "orillacart-addon/{id}", each setting an
 * attribute the owner fills in the block's sidebar (assets/admin/blocks/site-blocks.tsx), rendered on the server
 * with what was set, checked against the settings: a text up to 2000 characters, a number, on or off, one of the
 * choices. An add-on's failure shows nothing and is logged. Registered on wp_loaded, as the product blocks are.
 */
#[AsHook('wp_loaded', method: 'register')]
#[AsHook('enqueue_block_editor_assets', method: 'editor')]
final readonly class AddOnSiteBlocks
{
    private const int MOST_TEXT = 2000;

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
                $attributes[$setting->key] = ['type' => match ($setting->type) {
                    Setting::BOOLEAN => 'boolean',
                    Setting::NUMBER => 'number',
                    default => 'string',
                }];
            }
            register_block_type(self::name($block), [
                'api_version' => '3',
                'title' => $block->title(),
                'description' => $block->description(),
                'category' => 'widgets',
                'icon' => 'admin-plugins',
                'attributes' => $attributes,
                'supports' => ['html' => false, 'align' => ['wide', 'full'], 'spacing' => ['margin' => true, 'padding' => true]],
                'editor_script_handles' => ['orillacart-blocks'],
                'style_handles' => ['orillacart-storefront'],
                'render_callback' => fn(array $attributes): string => $this->render($block, $attributes),
            ]);
        }
    }

    /** What the editor builds each block's sidebar from. */
    public function editor(): void
    {
        $blocks = array_map(static fn(SiteBlock $block): array => [
            'name' => self::name($block),
            'settings' => array_map(static fn(Setting $setting): array => ['key' => $setting->key, 'label' => $setting->label, 'type' => $setting->type, 'help' => $setting->help, 'choices' => $setting->choices], self::settingsOf($block)),
        ], $this->extensions->siteBlocks());
        if ($blocks !== []) {
            wp_add_inline_script('orillacart-blocks', 'window.orillacartSiteBlocks = ' . wp_json_encode($blocks) . ';', 'before');
        }
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
     * @return array<string, string|int|float|bool>
     */
    public static function values(SiteBlock $block, array $attributes): array
    {
        $values = [];
        foreach (self::settingsOf($block) as $setting) {
            $value = $attributes[$setting->key] ?? null;
            $checked = match ($setting->type) {
                Setting::BOOLEAN => \is_bool($value) ? $value : null,
                Setting::NUMBER => \is_int($value) || \is_float($value) ? $value : (\is_string($value) && is_numeric($value) ? $value + 0 : null),
                Setting::CHOICE => \is_string($value) && \array_key_exists($value, $setting->choices) ? $value : null,
                default => \is_string($value) ? mb_substr($value, 0, self::MOST_TEXT) : null,
            };
            if ($checked !== null) {
                $values[$setting->key] = $checked;
            }
        }

        return $values;
    }

    /**
     * @param array<mixed> $attributes
     */
    private function render(SiteBlock $block, array $attributes): string
    {
        try {
            $html = $block->render(self::values($block, $attributes));
        } catch (\Throwable $e) {
            $this->logger->error('The site block {block} failed: {message}', ['block' => self::name($block), 'message' => $e->getMessage()]);

            return '';
        }

        return $html === '' ? '' : \sprintf('<div %s>%s</div>', get_block_wrapper_attributes(), $html);
    }
}
