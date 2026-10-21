<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application\Dto;

/** A new key, with the key itself: shown this once, never again. */
final readonly class CreatedApiKeyView
{
    public function __construct(
        public string $key,
        public ApiKeyView $apiKey,
    ) {}
}
