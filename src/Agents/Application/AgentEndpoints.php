<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Application;

/** Where the shop answers agents, and the links it hands buyers. */
interface AgentEndpoints
{
    /** Where agents read the shop's profile: /.well-known/ucp at the site's root. */
    public function profile(): string;

    /** The REST endpoint, without a trailing slash: OpenAPI paths go after it. */
    public function rest(): string;

    public function mcp(): string;

    /** The shop's own address, for a buyer to go on at when nothing could be put in a cart. */
    public function shop(): string;

    /** The link that hands the session's cart to the buyer's browser at the shop's checkout, signed for it alone. */
    public function continueUrl(string $checkoutId): string;

    /** Whether a link's signature is the session's. */
    public function verify(string $checkoutId, string $signature): bool;
}
