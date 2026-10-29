<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Storefront;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\Container\LocatedServices;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Registers the shop's blocks on init from their block.json (blocks/<name>), so the editor, translations and the
 * block directory read them as WordPress expects. A block is built only when a page renders it.
 */
final readonly class BlockRegistrar implements Registrar
{
    /**
     * @param ServiceLocator<BlockDefinition> $blocks by name
     */
    public function __construct(
        #[AutowireLocator(BlockDefinition::TAG, defaultIndexMethod: 'name')]
        private ServiceLocator $blocks,
        private PluginContext $context,
    ) {}

    public function register(): void
    {
        add_action('init', $this->registerBlocks(...));
    }

    public function registerBlocks(): void
    {
        foreach (LocatedServices::classes($this->blocks) as $name => $class) {
            if (!is_a($class, BlockDefinition::class, true)) {
                continue;
            }
            register_block_type($this->context->path('blocks/' . substr($name, \strlen('orillacart/'))), [
                'render_callback' => fn(array $attributes, string $content = '', ?\WP_Block $block = null): string => self::wrap($this->blocks->get($name)->render(self::named($attributes), BlockContext::of($content, $block))),
            ]);
        }
    }

    /**
     * Wraps the block in the element block supports style, with its alignment and class names.
     */
    private static function wrap(string $html): string
    {
        return $html === '' ? '' : \sprintf('<div %s>%s</div>', get_block_wrapper_attributes(), $html);
    }

    /**
     * @param array<mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private static function named(array $attributes): array
    {
        return array_filter($attributes, \is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
