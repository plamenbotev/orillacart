<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Storefront;

use OrillaCart\Extension\Storefront\BlockPattern;
use OrillaCart\Platform\Twig\ScriptFreeHtml;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Shared\WordPress\AsHook;
use Psr\Log\LoggerInterface;

/**
 * Add-ons' and extensions' block patterns (extension API 28, design 73), registered as
 * "orillacart-addon/{id}". Inserting one writes its markup into the post, where an administrator's save keeps any
 * script, so what it holds is cleaned as an extension's template prints (ScriptFreeHtml), and a pattern whose
 * blocks' attributes hold HTML (a block may print an attribute as it is) is left out and logged.
 */
#[AsHook('init', method: 'register', priority: 20)]
final readonly class AddOnBlockPatterns
{
    public const string NAMESPACE = 'orillacart-addon';
    public const string CATEGORY = 'shop';

    public function __construct(private InstalledExtensions $extensions, private LoggerInterface $logger) {}

    public function register(): void
    {
        $patterns = $this->extensions->blockPatterns();
        if ($patterns === []) {
            return;
        }
        if (!\WP_Block_Pattern_Categories_Registry::get_instance()->is_registered(self::CATEGORY)) {
            register_block_pattern_category(self::CATEGORY, ['label' => __('Shop', 'orillacart')]);
        }
        foreach ($patterns as $pattern) {
            try {
                $content = self::content($pattern);
            } catch (\Throwable $e) {
                $this->logger->error('The block pattern {pattern} failed: {message}', ['pattern' => $pattern->id(), 'message' => $e->getMessage()]);
                continue;
            }
            if ($content === null) {
                $this->logger->warning('The block pattern {pattern} is left out: its blocks\' attributes hold HTML, or it holds nothing.', ['pattern' => $pattern->id()]);
                continue;
            }
            register_block_pattern(self::NAMESPACE . '/' . str_replace('_', '-', $pattern->id()), [
                'title' => $pattern->title(),
                'description' => $pattern->description(),
                'categories' => $pattern->categories() === [] ? [self::CATEGORY] : $pattern->categories(),
                'content' => $content,
            ]);
        }
    }

    /** The pattern's markup as it may be inserted; null when it may not. */
    public static function content(BlockPattern $pattern): ?string
    {
        $content = trim($pattern->content());
        if ($content === '') {
            return null;
        }
        foreach (parse_blocks($content) as $block) {
            if (self::holdsHtml($block)) {
                return null;
            }
        }

        return ScriptFreeHtml::clean($content);
    }

    /**
     * @param array<mixed> $block
     */
    private static function holdsHtml(array $block): bool
    {
        $html = false;
        $attributes = \is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        array_walk_recursive($attributes, static function (mixed $value) use (&$html): void {
            if (\is_string($value) && str_contains($value, '<')) {
                $html = true;
            }
        });
        if ($html) {
            return true;
        }
        foreach (\is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [] as $inner) {
            if (\is_array($inner) && self::holdsHtml($inner)) {
                return true;
            }
        }

        return false;
    }
}
