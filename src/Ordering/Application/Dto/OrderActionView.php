<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * A button an add-on puts on the order screen.
 */
final readonly class OrderActionView
{
    public function __construct(
        public string $id,
        public string $label,
    ) {}
}
