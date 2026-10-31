<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A price of its own with no product: a label, so much each and how many (a deposit for six bottles, gift
 * wrapping). An add-on kept it in the cart, or the shop added it to the order. Taxed by its tax group, refunded by
 * quantity; it takes no stock and ships nothing.
 */
#[ORM\Entity]
class ChargeLine extends OrderLine
{
    /** Who charges it: "add_on:<type>" for an add-on's cart line type, "shop" for the shop's own. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $chargeSource = null;

    #[ORM\Column(nullable: true)]
    private ?bool $chargeTaxable = null;

    #[ORM\Column(nullable: true)]
    private ?int $chargeTaxGroupId = null;

    /** @var list<int>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $chargeFor = null;

    public function __construct(Order $order, LineDraft $draft, int $position)
    {
        parent::__construct($order, $draft, $position);
        $this->chargeSource = mb_substr($draft->charge->source ?? '', 0, 50);
        $this->chargeTaxable = $draft->charge->taxable ?? true;
        $this->chargeTaxGroupId = $draft->charge?->taxGroupId;
        $this->chargeFor = array_values($draft->charge->forProductIds ?? []);
    }

    public function type(): LineType
    {
        return LineType::Charge;
    }

    public function charge(): Charge
    {
        return new Charge($this->chargeSource ?? '', $this->chargeTaxable ?? true, $this->chargeTaxGroupId, $this->chargeFor ?? []);
    }

    protected function check(LineDraft $draft): void
    {
        if ($draft->productId !== null || $draft->charge === null) {
            throw new \LogicException('A charge names who charges it, and no product.');
        }
    }
}
