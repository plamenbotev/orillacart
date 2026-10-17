<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain\Event;

/**
 * A domain event that is also announced to WordPress as the action "orillacart_{hookName}",
 * so code outside the plugin can react to it. Its arguments are plain values, never entities.
 */
interface PublicEvent extends DomainEvent
{
    /** The action name without the "orillacart_" prefix, in snake_case. */
    public function hookName(): string;

    /** @return list<scalar|array<array-key, mixed>|null> */
    public function hookArguments(): array;
}
