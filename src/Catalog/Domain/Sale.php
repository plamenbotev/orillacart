<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * A reduced price for a while. Either end may be open: no start means it runs from now on,
 * no end means until it is removed.
 */
final readonly class Sale
{
    public function __construct(
        public Money $price,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $until = null,
    ) {
        if ($price->isNegative()) {
            throw new CatalogRuleViolation('A sale price cannot be negative.', 'orillacart_invalid_price');
        }
        if ($from !== null && $until !== null && $until <= $from) {
            throw new CatalogRuleViolation('A sale has to end after it starts.', 'orillacart_invalid_sale_period');
        }
    }

    public function isActiveAt(\DateTimeImmutable $now): bool
    {
        return ($this->from === null || $this->from <= $now) && ($this->until === null || $now < $this->until);
    }
}
