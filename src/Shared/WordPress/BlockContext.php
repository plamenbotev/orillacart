<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

/**
 * Where a block renders: the post the page, a Query Loop or a product card gives it (WordPress's postId
 * context), the blocks inside it as parse_blocks() gives them, and their HTML rendered with that context.
 */
final readonly class BlockContext
{
    /**
     * @param list<array<mixed>> $innerBlocks
     */
    public function __construct(
        public ?int $postId = null,
        public array $innerBlocks = [],
        public string $content = '',
    ) {}

    /** What WordPress gives a block's render callback. */
    public static function of(string $content, ?\WP_Block $block): self
    {
        $postId = $block?->context['postId'] ?? null;
        $inner = $block?->parsed_block['innerBlocks'] ?? [];

        return new self(
            is_numeric($postId) && (int) $postId > 0 ? (int) $postId : null,
            \is_array($inner) ? array_values(array_filter($inner, \is_array(...))) : [],
            $content,
        );
    }
}
