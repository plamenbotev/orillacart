<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application;

/**
 * Where the shop's OAuth server and the MCP endpoint it protects are.
 */
interface OAuthEndpoints
{
    /** The issuer, an https (or local http) address without a query; null when the site cannot offer OAuth. */
    public function issuer(): ?string;

    /** The MCP endpoint the tokens are for: the resource, as RFC 8707 names it. */
    public function resource(): string;

    public function authorization(): string;

    public function token(): string;

    public function registration(): string;

    /** The protected resource metadata (RFC 9728) of the MCP endpoint. */
    public function protectedResource(): string;
}
