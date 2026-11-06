<?php

declare(strict_types=1);

/**
 * HyperDB's shape as far as WordPressConnection reads it: a handle per server in $dbhs, and db_connect($query)
 * that only parses the statement to name the server. Records what it was asked.
 */
class hyperdb extends \wpdb
{
    /** @var array<string, \mysqli>|null */
    public ?array $dbhs = null;
    /** @var list<string> */
    public array $asked = [];
    public mixed $answer = null;

    public function __construct() {} // @phpstan-ignore constructor.missingParentCall

    // The drop-in's own signature, not core's.
    public function db_connect($query = ''): mixed // @phpstan-ignore parameter.defaultValue
    {
        $this->asked[] = \is_string($query) ? $query : '';

        return $this->answer; // @phpstan-ignore return.type
    }
}
