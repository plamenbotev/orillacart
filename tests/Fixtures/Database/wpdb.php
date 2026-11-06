<?php

declare(strict_types=1);

/**
 * Core wpdb as far as WordPressConnection reads it, for unit tests that run without WordPress: one handle,
 * protected but readable. Loaded only when WordPress is not; phpstan knows the real class from its stubs.
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
}
