<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\Attribute;

final readonly class AttributeView
{
    /**
     * @param list<OptionView> $options in display order
     * @param int|null         $setId   the attribute set it comes from; null for a product's own attribute
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $required,
        public bool $priceHidden,
        public array $options,
        public ?int $setId,
    ) {}

    public static function fromAttribute(Attribute $attribute): self
    {
        return new self(
            $attribute->id(),
            $attribute->name(),
            $attribute->isRequired(),
            $attribute->isPriceHidden(),
            array_map(OptionView::fromOption(...), $attribute->options()),
            $attribute->set()?->id(),
        );
    }
}
