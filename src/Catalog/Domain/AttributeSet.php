<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Attributes that several products share, such as "Clothing" with size and gift wrap. Products that use the set
 * offer its attributes while the set is active; changing the set changes them all.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attribute_sets')]
class AttributeSet
{
    use AttributeOwner;

    public const int MAX_NAME_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column]
    private bool $active;

    /** @var Collection<int, Attribute> */
    #[ORM\OneToMany(targetEntity: Attribute::class, mappedBy: 'set', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $attributes;

    public function __construct(string $name, bool $active = true)
    {
        $this->attributes = new ArrayCollection();
        $this->rename($name);
        $this->active = $active;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The attribute set has no id until it is saved.');
    }

    public function isSaved(): bool
    {
        return $this->id !== null;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new CatalogRuleViolation(\sprintf('An attribute set needs a name of up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_attribute_set_name');
        }
        $this->name = $name;
    }

    /** An inactive set keeps its attributes but no product offers them. */
    public function activate(bool $active): void
    {
        $this->active = $active;
    }

    /**
     * @param list<AttributeDraft> $drafts the set's whole list of attributes, in display order
     *
     * @throws CatalogRuleViolation for an attribute or option id that is not this set's
     */
    public function replaceAttributes(array $drafts): void
    {
        $this->syncAttributes($drafts, fn(AttributeDraft $draft, int $position): Attribute => new Attribute(null, $this, $draft, $position));
    }
}
