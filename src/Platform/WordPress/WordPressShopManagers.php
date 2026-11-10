<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\Users\ShopManagers;

final readonly class WordPressShopManagers implements ShopManagers
{
    public function manages(int $userId): bool
    {
        $user = $userId > 0 ? get_userdata($userId) : false;

        // On a network, a user removed from the site keeps their account but not its roles.
        return $user instanceof \WP_User && $user->exists() && user_can($user, Capability::MANAGE_SHOP);
    }
}
