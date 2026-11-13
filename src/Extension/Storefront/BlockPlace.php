<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Storefront;

/**
 * Where a site block is shown (API 28): the post or page it is on, whether the editor is showing it, and the blocks
 * the owner placed inside it, rendered (a PlacedSiteBlock that is inner()), which the block prints as they are.
 */
final readonly class BlockPlace
{
    public function __construct(
        public ?int $postId = null,
        public bool $preview = false,
        public string $content = '',
    ) {}
}
