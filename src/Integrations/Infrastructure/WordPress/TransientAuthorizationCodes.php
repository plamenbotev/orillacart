<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress;

use OrillaCart\Integrations\Application\AuthorizationCodes;

/**
 * Authorization codes kept as transients under their hash: the code itself is never stored. Redeeming deletes the
 * transient first, and only the request that deleted it gets what it grants, so two at once cannot both have it.
 */
final readonly class TransientAuthorizationCodes implements AuthorizationCodes
{
    private const string KEY = 'orillacart_oac_';
    private const string SPENT = 'orillacart_oacs_';
    /** As long as a code lasts: a code presented after that is refused as unknown either way. */
    private const int SPENT_SECONDS = 600;

    public function issue(array $grant, int $seconds): string
    {
        $code = 'oac_' . bin2hex(random_bytes(24));
        set_transient(self::KEY . hash('sha256', $code), $grant, $seconds);

        return $code;
    }

    public function redeem(string $code): ?array
    {
        if (preg_match('/^oac_[a-f0-9]{48}$/', $code) !== 1) {
            return null;
        }
        $key = self::KEY . hash('sha256', $code);
        $grant = get_transient($key);
        if (!\is_array($grant) || !delete_transient($key)) {
            return null;
        }
        set_transient(self::SPENT . hash('sha256', $code), ['key' => $grant['key'] ?? null, 'client' => $grant['client'] ?? null], self::SPENT_SECONDS);

        return array_filter($grant, \is_string(...), ARRAY_FILTER_USE_KEY);
    }

    public function spent(string $code): ?array
    {
        if (preg_match('/^oac_[a-f0-9]{48}$/', $code) !== 1) {
            return null;
        }
        $spent = get_transient(self::SPENT . hash('sha256', $code));

        return \is_array($spent) ? array_filter($spent, \is_string(...), ARRAY_FILTER_USE_KEY) : null;
    }
}
