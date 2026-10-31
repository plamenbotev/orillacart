<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * What the shopper put in the cart: theirs to change and remove.
 */
#[ORM\Entity]
class ProductLine extends CartLine
{
    public function type(): string
    {
        return 'product';
    }

    public function quantityChangeable(): bool
    {
        return true;
    }
}
