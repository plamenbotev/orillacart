<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * A tax group, or one of its rates, was created, changed or deleted; prices that include tax may differ now.
 * Announced as the action orillacart_tax_rates_changed with the group id.
 */
final readonly class TaxRatesChanged implements PublicEvent
{
    public function __construct(public int $groupId) {}

    public function hookName(): string
    {
        return 'tax_rates_changed';
    }

    /**
     * @return list<int>
     */
    public function hookArguments(): array
    {
        return [$this->groupId];
    }
}
