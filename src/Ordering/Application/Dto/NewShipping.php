<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

final readonly class NewShipping
{
    public function __construct(
        public string $option,
        public string $carrier,
        public string $rate,
        public string $price,
        public string $tax,
    ) {}
}
