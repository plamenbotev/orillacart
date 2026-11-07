<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Infrastructure\WordPress;

use OrillaCart\Reports\Application\HomeCache;

/** Each user's own: what add-ons put on the Home screen may depend on who looks. */
final readonly class TransientHomeCache implements HomeCache
{
    private const string PREFIX = 'orillacart_home_';

    public function get(string $key): ?array
    {
        $overview = get_transient(self::name($key));
        if (!\is_array($overview)) {
            return null;
        }
        $kept = [];
        foreach ($overview as $name => $value) {
            if (!\is_string($name)) {
                return null;
            }
            $kept[$name] = $value;
        }

        return $kept;
    }

    public function put(string $key, array $overview, int $seconds): void
    {
        set_transient(self::name($key), $overview, $seconds);
    }

    private static function name(string $key): string
    {
        return self::PREFIX . get_current_user_id() . '_' . $key;
    }
}
