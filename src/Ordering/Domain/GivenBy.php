<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

/**
 * The discount that gave an item: its code and description as they were when the order was placed.
 */
final readonly class GivenBy
{
    public function __construct(
        public string $code,
        public string $description = '',
    ) {}
}
