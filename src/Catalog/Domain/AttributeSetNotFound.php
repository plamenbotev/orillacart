<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class AttributeSetNotFound extends NotFound
{
    public function __construct(public readonly int $setId)
    {
        parent::__construct('There is no attribute set %d.', 'orillacart_attribute_set_not_found', values: [$setId]);
    }
}
