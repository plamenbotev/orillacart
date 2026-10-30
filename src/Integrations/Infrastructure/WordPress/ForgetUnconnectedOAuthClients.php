<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress;

use OrillaCart\Integrations\Application\OAuthServer;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Daily: forgets the OAuth clients that registered themselves and were never connected by the owner.
 */
final readonly class ForgetUnconnectedOAuthClients implements ScheduledTask
{
    public function __construct(private OAuthServer $server) {}

    public static function hook(): string
    {
        return 'orillacart_forget_unconnected_oauth_clients';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $this->server->forgetUnconnectedClients();
    }
}
