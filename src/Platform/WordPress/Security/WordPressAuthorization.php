<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Shared\Http\Security\Authorization;

final readonly class WordPressAuthorization implements Authorization
{
    public function isLoggedIn(): bool
    {
        return is_user_logged_in();
    }

    public function userId(): ?int
    {
        $id = get_current_user_id();

        return $id > 0 ? $id : null;
    }

    public function isGranted(string $capability): bool
    {
        return current_user_can($capability);
    }
}
