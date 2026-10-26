<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

use OrillaCart\Assistant\Application\Dto\Caller;

/**
 * What the request to the assistant's endpoint says about who sent it.
 */
interface AssistantRequests
{
    /** The shop manager calling, as the key or login says; null when nobody is logged in. */
    public function caller(): ?Caller;

    /** Whether a browser's Origin may call the endpoint: the site's own, or one the site allows. */
    public function allowsOrigin(string $origin): bool;
}
