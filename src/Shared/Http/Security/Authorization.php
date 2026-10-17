<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Security;

/**
 * Answers who the current user is allowed to be; WordPress roles and capabilities in production.
 */
interface Authorization
{
    public function isLoggedIn(): bool;

    /** The logged-in user's id; null for visitors. */
    public function userId(): ?int;

    public function isGranted(string $capability): bool;
}
