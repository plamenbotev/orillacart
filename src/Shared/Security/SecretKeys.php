<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Security;

/**
 * Which keys hold secrets, for what the change log blanks out: the keys of settings declared Setting::SECRET by
 * the gateways, shipping methods and extensions the request handled, and, as a net under those, keys named as
 * secrets ("client_secret", "password", "api_key", "token", "key"). Where a secret goes is declared with it
 * (Setting::$goesWith, the settings archive's sections), never guessed from names.
 */
final class SecretKeys
{
    private const string NAMED = '/(secret|password|passwd|token|api_?key|private)|^key$/i';

    /** @var array<string, true> the declared secret settings seen while handling this request */
    private static array $declared = [];
    /** @var array<string, true> the settings declared not secret while handling this request */
    private static array $plain = [];

    /** The keys of settings declared secret, as the code that checks them reads their declarations. */
    public static function declare(string ...$keys): void
    {
        foreach ($keys as $key) {
            self::$declared[$key] = true;
        }
    }

    /** The keys of settings declared as not secret (text, a number, a choice, on or off). */
    public static function declarePlain(string ...$keys): void
    {
        foreach ($keys as $key) {
            self::$plain[$key] = true;
        }
    }

    /**
     * Whether a key of a map of settings holds a secret: unless the request declared it plain (and no owner
     * declared it secret), it is taken to, since a setting the request did not declare could be anything.
     */
    public static function isSecretSetting(string $key): bool
    {
        return !isset(self::$plain[$key]) || self::isSecret($key);
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

    /** Forgets what earlier requests declared: for tests. */
    public static function reset(): void
    {
        self::$declared = [];
        self::$plain = [];
    }
}
