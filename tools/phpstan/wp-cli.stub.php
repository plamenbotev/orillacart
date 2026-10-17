<?php

declare(strict_types=1);

// The WP-CLI methods the plugin calls. php-stubs/wp-cli-stubs still requires WordPress stubs below 7.

final class WP_CLI
{
    /**
     * @param callable|class-string|object $callable
     * @param array<string, mixed> $args
     */
    public static function add_command(string $name, $callable, array $args = []): bool
    {
        return true;
    }

    public static function success(string $message): void {}

    public static function log(string $message): void {}
}
