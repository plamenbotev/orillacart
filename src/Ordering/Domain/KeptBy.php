<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

/**
 * The add-on's cart line type that kept an item in the cart: its id and label as they were when the order was
 * placed.
 */
final readonly class KeptBy
{
    public function __construct(
        public string $type,
        public string $label = '',
    ) {}
}
