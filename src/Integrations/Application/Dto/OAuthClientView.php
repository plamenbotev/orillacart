<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application\Dto;

/**
 * An OAuth client as the consent screen names it.
 */
final readonly class OAuthClientView
{
    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $redirectUris,
        public ?string $uri = null,
    ) {}
}
