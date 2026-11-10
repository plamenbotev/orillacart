<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\WordPress\Hooks;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Newsletter\Infrastructure\WordPress\FollowEmailChangeOnOtherShops;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A customer changed their email address in WordPress, on their profile, over WordPress's REST API
 * (`/wp/v2/users/me`) or by an administrator: wp_update_user() fires profile_update for each. Their newsletter
 * sign-up follows it, waiting to be confirmed from the new address, and the lists hear the old one left. On a
 * network the account is the network's, so the sign-up follows it on every shop of it, by a job.
 */
#[AsHook('profile_update', arguments: 2)]
final readonly class FollowEmailChanges
{
    public function __construct(
        private Newsletter $newsletter,
        private Jobs $jobs,
    ) {}

    public function __invoke(mixed $userId, mixed $before): void
    {
        $user = is_numeric($userId) ? get_userdata((int) $userId) : false;
        if ($user instanceof \WP_User && $before instanceof \WP_User && $before->user_email !== $user->user_email) {
            $this->newsletter->changeEmail($before->user_email, $user->user_email);
            if (is_multisite()) {
                $this->jobs->push(FollowEmailChangeOnOtherShops::TYPE, ['from' => $before->user_email, 'to' => $user->user_email]);
            }
        }
    }
}
