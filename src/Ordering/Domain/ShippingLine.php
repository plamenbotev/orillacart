<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * What shipping costs: an order that ships has one.
 */
#[ORM\Entity]
class ShippingLine extends OrderLine
{
    public function type(): LineType
    {
        return LineType::Shipping;
    }

    protected function check(LineDraft $draft): void
    {
        if ($draft->productId !== null) {
            throw new \LogicException('The shipping names no product.');
        }
    }
}
