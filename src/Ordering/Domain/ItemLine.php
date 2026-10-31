<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A product sold, with the options chosen: kept as it was sold.
 */
#[ORM\Entity]
class ItemLine extends OrderLine
{
    public function type(): LineType
    {
        return LineType::Item;
    }

    public function isItem(): bool
    {
        return true;
    }

    protected function check(LineDraft $draft): void
    {
        if ($draft->productId === null) {
            throw new \LogicException('An item line names its product.');
        }
    }
}
