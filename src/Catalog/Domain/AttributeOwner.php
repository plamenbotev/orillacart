<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\Common\Collections\Collection;

/**
 * Keeps a list of attributes in step with what the admin screen sends: the whole list at once, in order.
 * Used by Product (its own attributes) and AttributeSet.
 *
 * @property Collection<int, Attribute> $attributes
 */
trait AttributeOwner
{
    /**
     * @return list<Attribute> this owner's attributes, in display order
     */
    public function ownAttributes(): array
    {
        $attributes = array_values($this->attributes->toArray());
        usort($attributes, static fn(Attribute $a, Attribute $b): int => [$a->position(), $a->isSaved() ? $a->id() : PHP_INT_MAX] <=> [$b->position(), $b->isSaved() ? $b->id() : PHP_INT_MAX]);

        return $attributes;
    }

    /**
     * Ids of the saved options that replacing the attributes with these drafts would remove.
     *
     * @param list<AttributeDraft> $drafts
     *
     * @return list<int>
     */
    public function optionIdsRemovedBy(array $drafts): array
    {
        $removed = [];
        $byId = [];
        foreach ($drafts as $draft) {
            if ($draft->id !== null) {
                $byId[$draft->id] = $draft;
            }
        }
        foreach ($this->attributes as $attribute) {
            if (!$attribute->isSaved()) {
                continue;
            }
            $draft = $byId[$attribute->id()] ?? null;
            $removed = [...$removed, ...($draft === null ? $attribute->savedOptionIds() : $attribute->optionIdsRemovedBy($draft))];
        }

        return $removed;
    }

    /**
     * @param list<AttributeDraft>                   $drafts the whole list, in display order
     * @param \Closure(AttributeDraft, int): Attribute $create
     *
     * @throws CatalogRuleViolation for an attribute or option id that is not this owner's
     */
    private function syncAttributes(array $drafts, \Closure $create): void
    {
        $kept = [];
        foreach ($drafts as $position => $draft) {
            if ($draft->id === null) {
                $this->attributes->add($create($draft, $position));
                continue;
            }
            $attribute = $this->attributes->findFirst(static fn(int $key, Attribute $attribute): bool => $attribute->isSaved() && $attribute->id() === $draft->id)
                ?? throw new CatalogRuleViolation('There is no attribute %d here.', 'orillacart_unknown_attribute', values: [$draft->id]);
            $attribute->change($draft, $position);
            $kept[$draft->id] = true;
        }
        foreach ($this->attributes->toArray() as $attribute) {
            if ($attribute->isSaved() && !isset($kept[$attribute->id()])) {
                $this->attributes->removeElement($attribute);
            }
        }
    }
}
