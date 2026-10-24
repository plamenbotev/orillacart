<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class ProductNotFound extends NotFound
{
    public function __construct(public readonly int $productId)
    {
        parent::__construct('There is no product %d.', 'orillacart_product_not_found', values: [$productId]);
    }
}
