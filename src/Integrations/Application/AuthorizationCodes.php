<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application;

/**
 * OAuth authorization codes: random, kept on the server with what they grant, short-lived, good once.
 */
interface AuthorizationCodes
{
    /**
     * @param array<string, mixed> $grant
     */
    public function issue(array $grant, int $seconds): string;

    /**
     * What the code grants, once; null for a code that is unknown, used or ran out.
     *
     * @return array<string, mixed>|null
     */
    public function redeem(string $code): ?array;

    /**
     * What a code that was redeemed already granted, until it would have run out: a code that comes back has
     * leaked, and the tokens it gave are ended (RFC 6749 4.1.2).
     *
     * @return array<string, mixed>|null
     */
    public function spent(string $code): ?array;
}
