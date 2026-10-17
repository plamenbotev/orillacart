<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Something the customer chooses when buying, such as size or gift wrap, with its options.
 * It belongs either to one product or to an attribute set that products share.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attributes')]
class Attribute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: AttributeDraft::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column]
    private bool $required;

    #[ORM\Column]
    private bool $priceHidden;

    #[ORM\Column]
    private int $position;

    /** @var Collection<int, AttributeOption> */
    #[ORM\OneToMany(targetEntity: AttributeOption::class, mappedBy: 'attribute', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $options;

    /**
     * Created through Product or AttributeSet; exactly one of them owns it.
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'attributes')]
        #[ORM\JoinColumn(name: 'product_id', nullable: true, onDelete: 'CASCADE')]
        private ?Product $product,
        #[ORM\ManyToOne(targetEntity: AttributeSet::class, inversedBy: 'attributes')]
        #[ORM\JoinColumn(name: 'set_id', nullable: true, onDelete: 'CASCADE')]
        private ?AttributeSet $set,
        AttributeDraft $draft,
        int $position,
    ) {
        if (($product === null) === ($set === null)) {
            throw new \LogicException('An attribute belongs to a product or to an attribute set.');
        }
        $this->options = new ArrayCollection();
        $this->change($draft, $position);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The attribute has no id until it is saved.');
    }

    public function isSaved(): bool
    {
        return $this->id !== null;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isPriceHidden(): bool
    {
        return $this->priceHidden;
    }

    public function position(): int
    {
        return $this->position;
    }

    /** The product it belongs to; null for an attribute of a set. */
    public function product(): ?Product
    {
        return $this->product;
    }

    /** The attribute set it comes from; null for a product's own attribute. */
    public function set(): ?AttributeSet
    {
        return $this->set;
    }

    /**
     * @return list<AttributeOption> in display order
     */
    public function options(): array
    {
        $options = array_values($this->options->toArray());
        usort($options, static fn(AttributeOption $a, AttributeOption $b): int => [$a->position(), $a->isSaved() ? $a->id() : PHP_INT_MAX] <=> [$b->position(), $b->isSaved() ? $b->id() : PHP_INT_MAX]);

        return $options;
    }

    /**
     * Takes the draft's name, flags and options: options with an id are changed, new ones added, missing ones removed.
     *
     * @internal for AttributeOwner
     *
     * @throws CatalogRuleViolation for an option id that is not one of this attribute's
     */
    public function change(AttributeDraft $draft, int $position): void
    {
        $this->name = trim($draft->name);
        $this->required = $draft->required;
        $this->priceHidden = $draft->priceHidden;
        $this->position = $position;

        $kept = [];
        foreach ($draft->options as $index => $optionDraft) {
            if ($optionDraft->id === null) {
                $this->options->add(new AttributeOption($this, $optionDraft, $index));
                continue;
            }
            $option = $this->savedOption($optionDraft->id)
                ?? throw new CatalogRuleViolation(\sprintf('The attribute "%s" has no option %d.', $this->name, $optionDraft->id), 'orillacart_unknown_option');
            $option->change($optionDraft, $index);
            $kept[$optionDraft->id] = true;
        }
        foreach ($this->options->toArray() as $option) {
            if ($option->isSaved() && !isset($kept[$option->id()])) {
                $this->options->removeElement($option);
            }
        }
    }

    /**
     * Ids of the saved options this draft would remove.
     *
     * @return list<int>
     */
    public function optionIdsRemovedBy(AttributeDraft $draft): array
    {
        $kept = array_filter(array_map(static fn(OptionDraft $option): ?int => $option->id, $draft->options), static fn(?int $id): bool => $id !== null);

        return array_values(array_diff($this->savedOptionIds(), $kept));
    }

    /**
     * @return list<int>
     */
    public function savedOptionIds(): array
    {
        return array_values(array_map(
            static fn(AttributeOption $option): int => $option->id(),
            array_filter($this->options->toArray(), static fn(AttributeOption $option): bool => $option->isSaved()),
        ));
    }

    private function savedOption(int $id): ?AttributeOption
    {
        return $this->options->findFirst(static fn(int $key, AttributeOption $option): bool => $option->isSaved() && $option->id() === $id);
    }
}
