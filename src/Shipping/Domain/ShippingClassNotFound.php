<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class ShippingClassNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct(\sprintf('There is no shipping class %d.', $id), 'orillacart_shipping_class_not_found');
    }
}
