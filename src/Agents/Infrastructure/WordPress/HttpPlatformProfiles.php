<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\WordPress;

use OrillaCart\Agents\Application\PlatformProfiles;
use OrillaCart\Agents\Application\UcpError;

/**
 * An agent's profile, fetched as UCP asks a business to: over https only, through WordPress's safe HTTP (no
 * private, loopback or special-use address, so a profile URL cannot reach inside the shop's network), no
 * redirects, at most 128 KiB, and kept an hour.
 */
final readonly class HttpPlatformProfiles implements PlatformProfiles
{
    public const int MOST_BYTES = 131072;
    private const int KEPT = 3600;

    public function fetch(string $url): array
    {
        $key = 'orillacart_ucp_agent_' . md5($url);
        $known = get_transient($key);
        if (\is_array($known)) {
            return self::member($known);
        }
        $response = wp_safe_remote_get($url, ['timeout' => 5, 'redirection' => 0, 'limit_response_size' => self::MOST_BYTES, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new UcpError(424, 'profile_unreachable', 'Your profile could not be read at ' . $url . '.');
        }
        $profile = json_decode(wp_remote_retrieve_body($response), true);
        if (!\is_array($profile)) {
            throw new UcpError(422, 'profile_malformed', 'Your profile is not JSON.');
        }
        $member = self::member($profile);
        set_transient($key, $profile, self::KEPT);

        return $member;
    }

    /**
     * @param array<mixed> $profile
     *
     * @return array<string, mixed>
     */
    private static function member(array $profile): array
    {
        $ucp = $profile['ucp'] ?? null;
        if (!\is_array($ucp) || !\is_string($ucp['version'] ?? null)) {
            throw new UcpError(422, 'profile_malformed', 'Your profile has no ucp member with its version.');
        }
        $member = [];
        foreach ($ucp as $name => $value) {
            $member[(string) $name] = $value;
        }

        return $member;
    }
}
