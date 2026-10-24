<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class PriceRuleNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct('Price rule %d does not exist.', 'orillacart_price_rule_not_found', values: [$id]);
    }
}
