<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress;

use OrillaCart\Integrations\Domain\ApiKeyScope;
use OrillaCart\Shared\Security\ReadOnlyRequest;

/** Read-only when the request came with a key (or an OAuth token) that only reads. */
final readonly class KeyReadOnlyRequest implements ReadOnlyRequest
{
    public function __construct(private ApiKeyRequest $request) {}

    public function readOnly(): bool
    {
        return $this->request->key?->scope === ApiKeyScope::Read;
    }
}
