<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

use OrillaCart\Assistant\Application\AssistantRequests;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Integrations\Domain\ApiKeyScope;
use OrillaCart\Integrations\Infrastructure\WordPress\ApiKeyRequest;

/**
 * The caller from WordPress's current user and the shop's API key, if the request came with one.
 */
final readonly class WordPressAssistantRequests implements AssistantRequests
{
    public function __construct(private ApiKeyRequest $keys) {}

    public function caller(): ?Caller
    {
        $user = get_current_user_id();
        if ($user === 0) {
            return null;
        }
        $key = $this->keys->key;

        return new Caller($user, $key?->keyId, $key?->scope === ApiKeyScope::Read);
    }

    /**
     * The site's own addresses, and those the orillacart_assistant_origins filter adds, such as a local MCP
     * inspector's ("http://localhost:6274"). Clients that are not browsers send no Origin and are not asked.
     */
    public function allowsOrigin(string $origin): bool
    {
        $allowed = [self::origin(home_url('/')), self::origin(site_url('/')), self::origin(admin_url('/'))];
        $added = apply_filters('orillacart_assistant_origins', []);
        foreach (\is_array($added) ? $added : [] as $extra) {
            if (\is_string($extra)) {
                $allowed[] = self::origin($extra);
            }
        }

        return \in_array(self::origin($origin), array_filter($allowed, static fn(?string $allowed): bool => $allowed !== null), true);
    }

    /** "https://shop.example:8443", lower-case, without a path; null when it is not an address. */
    private static function origin(string $url): ?string
    {
        $parts = wp_parse_url(trim($url));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
