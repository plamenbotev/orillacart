<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Infrastructure\WordPress\Check;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Setup\Application\Dto\Readiness;
use OrillaCart\Setup\Application\ReadinessCheck;
use OrillaCart\Shared\Domain\Text;

/** A product to sell, published. */
final readonly class ProductsReadiness implements ReadinessCheck
{
    public function check(): array
    {
        $counts = wp_count_posts(ProductPostType::NAME);
        $published = is_numeric($counts->publish ?? null) ? (int) $counts->publish : 0;

        return $published > 0 ? [] : [Readiness::stop('products.none', Text::of('No product is published.'))];
    }
}
