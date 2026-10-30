<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * A product's details were saved from what was loaded, and someone saved them since: nothing was saved, so their
 * change is not lost. Load the product again, as it is now, before saving.
 */
final class ProductChangedMeanwhile extends RuleViolation
{
    public function __construct(public readonly int $productId)
    {
        parent::__construct('The product was changed since it was loaded: its details now are shown. Make your changes again and save.', 'orillacart_product_changed');
    }
}
