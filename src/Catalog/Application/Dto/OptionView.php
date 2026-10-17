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
    ) {}

    public static function fromOption(AttributeOption $option): self
    {
        return new self($option->id(), $option->name(), (string) $option->adjustment());
    }
}
