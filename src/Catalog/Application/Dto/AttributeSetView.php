<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\AttributeSet;

final readonly class AttributeSetView
{
    /**
     * @param list<AttributeView> $attributes in display order
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
        public array $attributes,
    ) {}

    public static function fromSet(AttributeSet $set): self
    {
        return new self($set->id(), $set->name(), $set->isActive(), array_map(AttributeView::fromAttribute(...), $set->ownAttributes()));
    }
}
