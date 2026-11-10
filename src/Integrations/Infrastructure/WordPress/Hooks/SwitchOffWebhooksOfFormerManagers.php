<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress\Hooks;

use OrillaCart\Integrations\Application\Webhooks;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A user whose role changed so they no longer manage the shop, or who is taken off the site, has the webhooks
 * they saved switched off at once. However else they stop managing it (a capability a plugin takes away), each
 * delivery finds out as it goes (DeliverWebhook).
 */
#[AsHook('set_user_role', method: 'roleChanged')]
#[AsHook('remove_user_role', method: 'roleChanged')]
#[AsHook('remove_user_from_blog', method: 'removed', arguments: 2)]
final readonly class SwitchOffWebhooksOfFormerManagers
{
    public function __construct(private Webhooks $webhooks) {}

    public function roleChanged(int|string $userId): void
    {
        $this->webhooks->ownerChanged((int) $userId);
    }

    /** Told before the user loses the site's roles: they are leaving this site's shop whatever their roles. */
    public function removed(int|string $userId, int|string $siteId): void
    {
        if ((int) $siteId === get_current_blog_id()) {
            $this->webhooks->ownerChanged((int) $userId, leaving: true);
        }
    }
}
