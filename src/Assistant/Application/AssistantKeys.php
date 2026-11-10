<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

use OrillaCart\Assistant\Application\Dto\AssistantChoices;
use OrillaCart\Integrations\Application\ApiKeys;

/**
 * What the owner chose, per API key, for the AI assistant that uses it.
 */
final readonly class AssistantKeys
{
    public function __construct(private ApiKeys $keys) {}

    /** The choices for a key the user may manage: their own, or any for an administrator. */
    public function choices(int $keyId, int $userId, bool $administrator): AssistantChoices
    {
        return AssistantChoices::fromArray($this->keys->assistant($keyId, $userId, $administrator));
    }

    /** Chooses for a key the user may manage: their own, or any for an administrator. */
    public function choose(int $keyId, AssistantChoices $choices, int $userId, bool $administrator): AssistantChoices
    {
        $this->keys->chooseForAssistant($keyId, $choices->toArray(), $userId, $administrator);

        return $this->choices($keyId, $userId, $administrator);
    }
}
