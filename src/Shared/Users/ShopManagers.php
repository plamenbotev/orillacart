<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Users;

/**
 * Who manages the shop now: what was set up in a manager's name (a webhook) stops when they no longer do.
 */
interface ShopManagers
{
    /** Whether the user exists and may manage the shop on this site. */
    public function manages(int $userId): bool;
}
