<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A product an add-on kept in the cart with what the customer bought (a deposit, a sample): an item like any
 * other, which also keeps the add-on's line type and its label, as they were.
 */
#[ORM\Entity]
class AddOnItemLine extends ItemLine
{
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $addOnType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $addOnLabel = null;

    public function __construct(Order $order, LineDraft $draft, int $position)
    {
        parent::__construct($order, $draft, $position);
        $this->addOnType = mb_substr($draft->addOn->type ?? '', 0, 40);
        $this->addOnLabel = mb_substr($draft->addOn->label ?? '', 0, 100);
    }

    public function type(): LineType
    {
        return LineType::AddOn;
    }

    public function addOn(): KeptBy
    {
        return new KeptBy($this->addOnType ?? '', $this->addOnLabel ?? '');
    }

    protected function check(LineDraft $draft): void
    {
        parent::check($draft);
        if ($draft->addOn === null) {
            throw new \LogicException('An add-on\'s item names its line type.');
        }
    }
}
