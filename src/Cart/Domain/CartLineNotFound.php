<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class CartLineNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct(\sprintf('The cart has no line %d.', $id), 'orillacart_cart_line_not_found');
    }
}
