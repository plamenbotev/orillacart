<?php

declare(strict_types=1);

/**
 * Core wpdb as far as WordPressConnection and DatabaseConfig read it, for unit tests and the docs generator that
 * run without WordPress: one handle, protected but readable. Loaded only when WordPress is not; phpstan knows the real class from its stubs.
 */
class wpdb
{
    public string $prefix = 'wp_3_';
    protected mixed $dbh = null;

    public function __construct() {}

    public function __get(string $name): mixed
    {
        return $this->$name;
    }

    public function db_connect($allow_bail = true): mixed
    {
        return $this->dbh;
    }

    /** As core parses DB_HOST: host, port, socket, whether IPv6. For DatabaseConfig without WordPress (the docs). */
    public function parse_db_host(string $host): array
    {
        return [$host, null, null, false];
    }

    public function db_server_info(): string
    {
        return '';
    }
}
