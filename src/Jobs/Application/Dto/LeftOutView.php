<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application\Dto;

/**
 * A network worker that goes through the network's shops leaves this one out: its jobs would run without its own
 * add-ons (plugins) or theme (its templates, its functions.php), and with the worker's. It needs a worker of its
 * own (`--url`) or WP-Cron.
 */
final readonly class LeftOutView
{
    /**
     * @param string $reason plugins or theme: what the site has that the worker did not load
     * @param string $at     when the worker last said so, ISO 8601
     */
    public function __construct(
        public string $worker,
        public string $reason,
        public string $at,
    ) {}
}
