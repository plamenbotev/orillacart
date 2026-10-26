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

    public function choices(int $keyId): AssistantChoices
    {
        return AssistantChoices::fromArray($this->keys->assistant($keyId));
    }

    public function choose(int $keyId, AssistantChoices $choices): AssistantChoices
    {
        $this->keys->chooseForAssistant($keyId, $choices->toArray());

        return $this->choices($keyId);
    }
}
