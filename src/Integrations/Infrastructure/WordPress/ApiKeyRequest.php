<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress;

use OrillaCart\Audit\Application\RequestApiKey;
use OrillaCart\Integrations\Application\Dto\AuthenticatedKey;

/**
 * The key the request authenticated with, if any: one per request, as WordPress handles one.
 */
final class ApiKeyRequest implements RequestApiKey
{
    public ?AuthenticatedKey $key = null;

    /** The key sent was not one the shop has: the request is refused, not served as a visitor's. */
    public bool $refused = false;

    /**
     * A key came, but WordPress asked who the user is before it served the REST request: the key's user is given
     * once it does (ApiKeyAuthentication::serving()).
     */
    public bool $deferred = false;

    public function keyName(): ?string
    {
        return $this->key?->name;
    }
}
