<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * One choice of an attribute, such as "M" for "Size". Choosing it adds its adjustment to the product price: an
 * amount, or a percentage of the product's price.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attribute_options')]
class AttributeOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: OptionDraft::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $adjustment;

    #[ORM\Column(length: 10, enumType: AdjustmentKind::class, options: ['default' => 'amount'])]
    private AdjustmentKind $adjustmentKind = AdjustmentKind::Amount;

    #[ORM\Column]
    private int $position;

    /**
     * Created through Attribute.
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Attribute::class, inversedBy: 'options')]
        #[ORM\JoinColumn(name: 'attribute_id', nullable: false, onDelete: 'CASCADE')]
        private Attribute $attribute,
        OptionDraft $draft,
        int $position,
    ) {
        $this->change($draft, $position);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The option has no id until it is saved.');
    }

    public function isSaved(): bool
    {
        return $this->id !== null;
    }

    public function attribute(): Attribute
    {
        return $this->attribute;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function adjustment(): Money
    {
        return Money::of($this->adjustment);
    }

    public function adjustmentKind(): AdjustmentKind
    {
        return $this->adjustmentKind;
    }

    /** What choosing it adds to a product of this price; negative when it takes off. */
    public function adjustmentOn(Money $price): Money
    {
        return $this->adjustmentKind->of($this->adjustment(), $price);
    }

    public function position(): int
    {
        return $this->position;
    }

    /**
     * @internal for Attribute
     */
    public function change(OptionDraft $draft, int $position): void
    {
        $this->name = trim($draft->name);
        $this->adjustment = $draft->adjustment->toFixed();
        $this->adjustmentKind = $draft->kind;
        $this->position = $position;
    }
}
