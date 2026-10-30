<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

use OrillaCart\Assistant\Application\RequestStates;

/**
 * Request states signed with the site's own secret (wp_salt and the site's id, as every site of a network
 * shares the salts), their time limit inside what is signed, and each claimed once used, in one statement so two
 * retries sent at once cannot both redeem it, until it would have run out.
 */
final readonly class SignedRequestStates implements RequestStates
{
    public function __construct(private OnceClaims $claims) {}

    public function issue(array $payload, int $seconds): string
    {
        $body = self::encode((string) json_encode(['p' => $payload, 'e' => time() + $seconds]));

        return $body . '.' . self::encode(hash_hmac('sha256', $body, self::secret(), true));
    }

    public function redeem(string $state): ?array
    {
        $parts = explode('.', $state);
        if (\count($parts) !== 2 || !hash_equals(self::encode(hash_hmac('sha256', $parts[0], self::secret(), true)), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!\is_array($data) || !\is_int($data['e'] ?? null) || !\is_array($data['p'] ?? null) || $data['e'] < time()) {
            return null;
        }
        if (!$this->claims->claim('mcp-state|' . $state, $data['e'] + 1)) {
            return null;
        }

        return array_filter($data['p'], \is_string(...), ARRAY_FILTER_USE_KEY);
    }

    private static function secret(): string
    {
        return wp_salt('auth') . '|' . get_current_blog_id() . '|orillacart-mcp';
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
