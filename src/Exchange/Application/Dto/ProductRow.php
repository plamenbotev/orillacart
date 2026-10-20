<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application\Dto;

use OrillaCart\Catalog\Application\Dto\ProductView;
use OrillaCart\Catalog\Application\Dto\VariationView;

/**
 * A row of the products file: a product, or one of its variations with its product.
 */
final readonly class ProductRow
{
    /**
    * @param array<int, int> $levels the row's stock, by stockroom id
     */
    public function __construct(
        public ProductView $product,
        public ?VariationView $variation,
        public ProductPost $post,
        public array $levels,
    ) {}
}
