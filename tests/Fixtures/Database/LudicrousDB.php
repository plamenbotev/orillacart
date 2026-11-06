<?php

declare(strict_types=1);

/**
 * LudicrousDB's shape as far as WordPressConnection reads it: like HyperDB, but db_connect keeps core's first
 * parameter, db_connect($allow_bail, $query).
 */
class LudicrousDB extends \wpdb
{
    /** @var array<string, \mysqli> */
    public array $dbhs = [];
    /** @var list<array{bool, string}> */
    public array $asked = [];
    public mixed $answer = null;

    public function __construct() {} // @phpstan-ignore constructor.missingParentCall

    // The drop-in's own signature, not core's.
    public function db_connect($allow_bail = true, $query = ''): mixed // @phpstan-ignore missingType.parameter
    {
        $this->asked[] = [(bool) $allow_bail, \is_string($query) ? $query : ''];

        return $this->answer; // @phpstan-ignore return.type
    }
}
