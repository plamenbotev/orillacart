<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * The shop added, changed or deleted a price rule: prices may differ anywhere in the catalog. Announced as the
 * action orillacart_price_rules_changed.
 */
final readonly class PriceRulesChanged implements PublicEvent
{
    public function hookName(): string
    {
        return 'price_rules_changed';
    }

    public function hookArguments(): array
    {
        return [];
    }
}
