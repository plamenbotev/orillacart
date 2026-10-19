<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * The shop added, changed or deleted a price rule: prices may differ for whom and what it is for, before and after
 * the change. A rule for no products, categories or brands is for the whole catalog; one for no customer groups is
 * for everyone, guests too. Announced as the action orillacart_price_rules_changed, with those scopes.
 *
 * @phpstan-type Scope array{groups: list<int>, products: list<int>, categories: list<int>, brands: list<int>}
 */
final readonly class PriceRulesChanged implements PublicEvent
{
    /**
     * @param list<Scope> $scopes the rule's before and after the change
     */
    public function __construct(public array $scopes) {}

    public function hookName(): string
    {
        return 'price_rules_changed';
    }

    /** @return list<list<Scope>> */
    public function hookArguments(): array
    {
        return [$this->scopes];
    }
}
