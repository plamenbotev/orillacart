<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\WordPress\Hooks;

use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Shared\WordPress\OtherShops;
use Psr\Container\ContainerInterface;

/**
 * A customer changed their email address in WordPress: their newsletter sign-up follows it, and the lists hear
 * of both addresses, instead of the old one staying subscribed. On a network the account is the network's, so the
 * sign-up follows it on every shop of it.
 */
#[AsHook('profile_update', arguments: 2)]
final readonly class FollowEmailChanges
{
    public function __construct(
        private Newsletter $newsletter,
        private OtherShops $shops,
    ) {}

    public function __invoke(mixed $userId, mixed $before): void
    {
        $user = is_numeric($userId) ? get_userdata((int) $userId) : false;
        if ($user instanceof \WP_User && $before instanceof \WP_User && $before->user_email !== $user->user_email) {
            $this->newsletter->changeEmail($before->user_email, $user->user_email);
            $from = $before->user_email;
            $to = $user->user_email;
            $this->shops->each(static function (ContainerInterface $shop) use ($from, $to): void {
                $newsletter = $shop->get(Newsletter::class);
                \assert($newsletter instanceof Newsletter);
                $newsletter->changeEmail($from, $to);
            });
        }
    }
}
