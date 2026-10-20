<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

/**
 * The site the shop runs on, as emails name it.
 */
interface Site
{
    public function name(): string;

    public function adminEmail(): string;

    public function url(): string;

    /** The email of a WordPress user; null for one that does not exist. */
    public function userEmail(int $userId): ?string;
}
