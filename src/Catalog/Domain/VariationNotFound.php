<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class VariationNotFound extends NotFound
{
    public function __construct(public readonly int $variationId)
    {
        parent::__construct(\sprintf('The product has no variation %d.', $variationId), 'orillacart_variation_not_found');
    }
}
