<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class TaxGroupNotFound extends NotFound
{
    public function __construct(public readonly int $groupId)
    {
        parent::__construct('There is no tax group %d.', 'orillacart_tax_group_not_found', values: [$groupId]);
    }
}
