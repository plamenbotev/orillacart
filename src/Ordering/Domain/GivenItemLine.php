<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A product a discount gave with the order (a free gift, the item buy-get gives free): an item like any other,
 * which also keeps the discount that gave it, as it was.
 */
#[ORM\Entity]
class GivenItemLine extends ItemLine
{
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $givenCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $givenDescription = null;

    public function __construct(Order $order, LineDraft $draft, int $position)
    {
        parent::__construct($order, $draft, $position);
        $this->givenCode = mb_substr($draft->givenBy->code ?? '', 0, 40);
        $this->givenDescription = mb_substr($draft->givenBy->description ?? '', 0, 255);
    }

    public function type(): LineType
    {
        return LineType::Given;
    }

    public function givenBy(): GivenBy
    {
        return new GivenBy($this->givenCode ?? '', $this->givenDescription ?? '');
    }

    protected function check(LineDraft $draft): void
    {
        parent::check($draft);
        if ($draft->givenBy === null) {
            throw new \LogicException('An item given names the discount that gave it.');
        }
    }
}
