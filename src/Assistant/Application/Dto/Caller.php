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
     */
    public function __construct(
        public int $userId,
        public ?int $keyId,
        public bool $readOnly,
        public AssistantChoices $choices = new AssistantChoices(),
        public ?string $keyName = null,
    ) {}
}
