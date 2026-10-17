<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Security;

/**
 * Tells requests the user meant to send from requests another site forged; WordPress nonces in production.
 */
interface CsrfProtection
{
    /**
     * False when the request authenticated without browser cookies (application passwords),
     * which a forged cross-site request cannot do.
     */
    public function isRequired(): bool;

    public function isTokenValid(string $token, string $action): bool;
}
