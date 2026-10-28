<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\AttributeOption;

final readonly class OptionView
{
    public function __construct(
        public int $id,
        public string $name,
        public string $adjustment,
        public string $adjustmentKind = 'amount',
        /** @var array<string, string> the name in the site's other languages, by locale, for the admin screens */
        public array $translations = [],
    ) {}

    public static function fromOption(AttributeOption $option, ?NameTranslations $translations = null): self
    {
        return new self($option->id(), $option->name(), (string) $option->adjustment(), $option->adjustmentKind()->value, $translations?->options[$option->id()] ?? []);
    }
}
