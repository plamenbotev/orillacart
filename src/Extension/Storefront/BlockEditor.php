<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Storefront;

/**
 * A site block's own editing screen (API 28): the document of a sandboxed frame in the block's sidebar, which reads
 * and sets the block's settings through window.orillacart.block, and may call the routes of the scripted extension
 * it names (`/x/<extension>/…`; '' for none).
 */
final readonly class BlockEditor
{
    public function __construct(
        public string $document,
        public string $extension = '',
    ) {}
}
