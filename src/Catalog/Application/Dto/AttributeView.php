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
        /** @var array<string, string> the name in the site's other languages, by locale, for the admin screens */
        public array $translations = [],
    ) {}

    public static function fromAttribute(Attribute $attribute, ?NameTranslations $translations = null): self
    {
        return new self(
            $attribute->id(),
            $attribute->name(),
            $attribute->isRequired(),
            $attribute->isPriceHidden(),
            array_map(static fn($option): OptionView => OptionView::fromOption($option, $translations), $attribute->options()),
            $attribute->set()?->id(),
            $translations?->attributes[$attribute->id()] ?? [],
        );
    }
}
