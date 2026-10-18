<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Lifecycle;

/**
 * Work for the end of this request, after the response is sent where the server allows (PHP-FPM), such as
 * building what the request changed. Not under WP-CLI, whose commands may remove the plugin before the process
 * ends; cron or a command does that work there.
 */
interface AfterResponse
{
    /** Runs the work once per key, however often it is deferred. */
    public function defer(string $key, \Closure $work): void;
}
