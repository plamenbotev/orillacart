<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\WordPress\Hooks;

use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A customer changed their email address in WordPress: their newsletter sign-up follows it, and the lists hear
 * of both addresses, instead of the old one staying subscribed.
 */
#[AsHook('profile_update', arguments: 2)]
final readonly class FollowEmailChanges
{
    public function __construct(private Newsletter $newsletter) {}

    public function __invoke(mixed $userId, mixed $before): void
    {
        $user = is_numeric($userId) ? get_userdata((int) $userId) : false;
        if ($user instanceof \WP_User && $before instanceof \WP_User && $before->user_email !== $user->user_email) {
            $this->newsletter->changeEmail($before->user_email, $user->user_email);
        }
    }
}
