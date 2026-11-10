<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application\Dto;

/**
 * Who is calling the assistant's tools: the shop manager the key or login acts as, and what the key allows.
 */
final readonly class Caller
{
    /**
     * @param int|null $keyId    the API key the request authenticated with, if it was one
     * @param bool     $readOnly the key only reads: tools that change something are not offered
     * @param AssistantChoices $choices what the owner chose for the key: the defaults without one
     * @param string|null      $keyName the key's name, for the change log
     * @param list<string>     $allowed the tools the owner let the key run unasked for a while, from their yes on
     *                                  the shop's page, as they stand now (those run out are not here)
     */
    public function __construct(
        public int $userId,
        public ?int $keyId,
        public bool $readOnly,
        public AssistantChoices $choices = new AssistantChoices(),
        public ?string $keyName = null,
        public array $allowed = [],
    ) {}

    /** Whether the owner's yes for a while covers this tool's call: only a key's, and only for a risk that allows it. */
    public function allows(ToolDefinition $tool): bool
    {
        return $this->keyId !== null && $this->choices->allowsFor($tool->risk) > 0 && \in_array($tool->name, $this->allowed, true);
    }
}
