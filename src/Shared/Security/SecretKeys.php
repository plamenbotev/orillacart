<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Security;

/**
 * Which keys hold secrets, one rule for what the change log blanks out and what a settings file leaves behind: the
 * keys of settings declared Setting::SECRET by the gateways, shipping methods and extensions the request handled,
 * and keys named as secrets ("client_secret", "password", "api_key", "token", "key"). Which keys say where a
 * secret goes (an address, a user) is here too: a secret is not kept for an address it was not typed for.
 */
final class SecretKeys
{
    private const string NAMED = '/(secret|password|passwd|token|api_?key|private)|^key$/i';
    private const string ENDPOINT = '/(^|_)(url|uri|host|hostname|endpoint|server|domain|port|base|user|username|login)$/i';

    /** @var array<string, true> the declared secret settings seen while handling this request */
    private static array $declared = [];

    /** The keys of settings declared secret, as the code that checks them reads their declarations. */
    public static function declare(string ...$keys): void
    {
        foreach ($keys as $key) {
            self::$declared[$key] = true;
        }
    }

    public static function isSecret(string $key): bool
    {
        return isset(self::$declared[$key]) || self::named($key);
    }

    /** Whether the key is named as a secret, whatever declared it. */
    public static function named(string $key): bool
    {
        return preg_match(self::NAMED, $key) === 1;
    }

    /**
     * Whether the setting says where a secret goes or whose it is: its key names an address or a user, or its
     * value is an address ("https://…", "api.example.com").
     */
    public static function endpoint(string $key, mixed $value = null): bool
    {
        if (preg_match(self::ENDPOINT, $key) === 1) {
            return true;
        }

        return \is_string($value) && (preg_match('#^[a-z][a-z0-9+.-]*://#i', trim($value)) === 1 || preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+(:\d+)?(\/\S*)?$/i', trim($value)) === 1 && preg_match('/[a-z]/i', trim($value)) === 1 && !is_numeric(trim($value)));
    }

    /** Forgets what earlier requests declared: for tests. */
    public static function reset(): void
    {
        self::$declared = [];
    }
}
