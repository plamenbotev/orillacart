<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Infrastructure\WordPress;

use OrillaCart\Reports\Application\Reports;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * Looks after the shop's reports.
 */
final readonly class ReportsCommand implements CliCommand
{
    public function __construct(private Reports $reports) {}

    public static function name(): string
    {
        return 'orillacart reports';
    }

    /**
     * Counts every order again, in the background: after importing orders, or changing the site's time zone. The
     * job workers do it a thousand orders at a time; `wp orillacart jobs status` shows how far it is.
     *
     * ## EXAMPLES
     *
     *     wp orillacart reports rebuild
     *
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function rebuild(array $args, array $options): void
    {
        $this->reports->rebuild();
        \WP_CLI::success('Every order is queued to be counted again.');
    }
}
