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

    public function isGranted(string $capability): bool
    {
        return current_user_can($capability);
    }
}
