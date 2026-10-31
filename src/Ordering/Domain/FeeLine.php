<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A fee: the shop's, an add-on's, or for the way to pay.
 */
#[ORM\Entity]
class FeeLine extends OrderLine
{
    public function type(): LineType
    {
        return LineType::Fee;
    }

    protected function check(LineDraft $draft): void
    {
        if ($draft->productId !== null) {
            throw new \LogicException('A fee names no product.');
        }
    }
}
