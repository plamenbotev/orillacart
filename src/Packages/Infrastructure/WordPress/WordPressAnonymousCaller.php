<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Packages\Application\AnonymousCaller;
use OrillaCart\Shared\Http\Security\CallerKeys;
use OrillaCart\Shared\Http\Security\ClientAddress;
use OrillaCart\Shared\Security\Capability;

/**
 * The web request's caller, unless it is the shop's staff: jobs run from the command line or WP-Cron, never at the
 * end of a visitor's request, so a run there is the visitor's.
 */
final readonly class WordPressAnonymousCaller implements AnonymousCaller
{
    public function who(): ?string
    {
        if (\PHP_SAPI === 'cli' || wp_doing_cron() || current_user_can(Capability::MANAGE_SHOP)) {
            return null;
        }
        $userId = get_current_user_id();

        return $userId > 0 ? 'user:' . $userId : CallerKeys::network('ip:' . ClientAddress::of($_SERVER));
    }
}
