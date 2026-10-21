<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress;

use OrillaCart\Integrations\Application\Dto\AuthenticatedKey;

/**
 * The key the request authenticated with, if any: one per request, as WordPress handles one.
 */
final class ApiKeyRequest
{
    public ?AuthenticatedKey $key = null;

    /** The key sent was not one the shop has: the request is refused, not served as a visitor's. */
    public bool $refused = false;
}
