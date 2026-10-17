<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;

final readonly class WordPressProductContent implements ProductContent
{
    public function exists(int $productId): bool
    {
        return get_post_type($productId) === ProductPostType::NAME;
    }

    public function isPublished(int $productId): bool
    {
        return $this->exists($productId) && get_post_status($productId) === 'publish';
    }

    public function name(int $productId): string
    {
        return $this->exists($productId) ? get_the_title($productId) : '';
    }
}
