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
}
