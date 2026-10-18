<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

final readonly class CustomerGroupView
{
    public function __construct(
        public int $id,
        public string $name,
        public int $members,
    ) {}
}
