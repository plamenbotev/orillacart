<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Catalog;

use OrillaCart\Catalog\Domain\Attribute;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\Variation;

/**
 * Numbers new catalog entities on save, one sequence per table, as the database does.
 */
final class CatalogIds
{
    /** @var array<class-string, int> */
    private array $next = [];

    /**
     * @param list<Attribute> $attributes
     */
    public function attributes(array $attributes): void
    {
        foreach ($attributes as $attribute) {
            if (!$attribute->isSaved()) {
                $this->assign($attribute);
            }
            foreach ($attribute->options() as $option) {
                if (!$option->isSaved()) {
                    $this->assign($option);
                }
            }
        }
    }

    public function set(AttributeSet $set): void
    {
        if (!$set->isSaved()) {
            $this->assign($set);
        }
        $this->attributes($set->ownAttributes());
    }

    public function variation(Variation $variation): void
    {
        if (!$variation->isSaved()) {
            $this->assign($variation);
        }
    }

    private function assign(Attribute|AttributeOption|AttributeSet|Variation $entity): void
    {
        $class = $entity::class;
        $this->next[$class] ??= 1;
        new \ReflectionProperty($class, 'id')->setValue($entity, $this->next[$class]++);
    }
}
