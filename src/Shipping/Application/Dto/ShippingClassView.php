<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\ShippingClass;

final readonly class ShippingClassView
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function fromClass(ShippingClass $class): self
    {
        return new self($class->id(), $class->name());
    }
}
