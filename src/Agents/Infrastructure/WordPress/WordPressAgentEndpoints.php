<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\WordPress;

use OrillaCart\Agents\Application\AgentEndpoints;

/**
 * The REST and MCP endpoints under the shop's namespace, and the link that hands a session's cart to the buyer:
 * the session's id with a signature of it, so only who was given the link opens that cart.
 */
final readonly class WordPressAgentEndpoints implements AgentEndpoints
{
    public const string SESSION = 'orillacart_agent_checkout';
    public const string SIGNATURE = 'orillacart_agent_sig';

    public function profile(): string
    {
        return home_url('/.well-known/ucp');
    }

    public function rest(): string
    {
        return untrailingslashit(rest_url('orillacart/v1/ucp'));
    }

    public function mcp(): string
    {
        return untrailingslashit(rest_url('orillacart/v1/ucp/mcp'));
    }

    public function shop(): string
    {
        return home_url('/');
    }

    public function continueUrl(string $checkoutId): string
    {
        return add_query_arg([self::SESSION => $checkoutId, self::SIGNATURE => self::sign($checkoutId)], home_url('/'));
    }

    public function verify(string $checkoutId, string $signature): bool
    {
        return hash_equals(self::sign($checkoutId), $signature);
    }

    private static function sign(string $checkoutId): string
    {
        return substr(hash_hmac('sha256', 'agent-checkout|' . $checkoutId, wp_salt('auth')), 0, 32);
    }
}
