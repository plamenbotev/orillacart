<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Cli;

use OrillaCart\Platform\CacheFolders;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Health\ShopHealth;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Shared\WordPress\CliCommand;
use OrillaCart\Shared\WordPress\HealthStatus;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionShopPagesRepository;

/**
 * Checks the shop as Site Health does, and with --fix repairs what it can.
 */
final readonly class DoctorCommand implements CliCommand
{
    public function __construct(
        private ShopHealth $health,
        private DatabaseMigrator $migrator,
        private PluginLifecycle $lifecycle,
        private OptionShopPagesRepository $pages,
        private SearchIndexing $indexing,
        private PluginContext $context,
    ) {}

    public static function name(): string
    {
        return 'orillacart doctor';
    }

    /**
     * Runs the shop's checks: its tables, cache folder, background jobs, downloads folder, search index, PayPal.
     * Ends with an error when a check finds something critical, so a deploy script can stop on it.
     *
     * With --fix it first repairs what it can: runs the changes to the tables that have not run, forgets what
     * the shop compiled (templates, database mapping), makes the shop pages that were deleted again, and rebuilds
     * the search index. Then it checks again.
     *
     * ## OPTIONS
     *
     * [--fix]
     * : Repair what can be repaired, then check.
     *
     * [--format=<format>]
     * : table, json or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp orillacart doctor
     *     wp orillacart doctor --fix
     *
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function __invoke(array $args, array $options): void
    {
        if (isset($options['fix'])) {
            $this->fix();
        }
        $rows = [];
        $critical = false;
        $labels = $this->health->labels();
        foreach ($this->health->checkAll() as $id => $status) {
            $critical = $critical || $status->status === HealthStatus::CRITICAL;
            $rows[] = ['check' => $labels[$id] ?? $id, 'status' => $status->status, 'finding' => $status->label, 'what to do' => $status->description];
        }
        \WP_CLI\Utils\format_items($options['format'] ?? 'table', $rows, ['check', 'status', 'finding', 'what to do']);
        if ($critical) {
            \WP_CLI::error('The shop needs fixing: see the critical findings above.');
        }
        \WP_CLI::success('The shop is healthy.');
    }

    private function fix(): void
    {
        $executed = $this->migrator->migrate();
        $this->lifecycle->upgradeIfNeeded();
        delete_option(PluginLifecycle::UPGRADE_FAILED_OPTION);
        \WP_CLI::log($executed === [] ? 'Tables: up to date.' : 'Tables: ran ' . implode(', ', $executed) . '.');

        CacheFolders::clear($this->context->build);
        \WP_CLI::log('Cache: cleared; the next request compiles the templates again.');

        $this->pages->activate();
        \WP_CLI::log('Shop pages: deleted ones made again.');

        \WP_CLI::log(\sprintf('Search index: %d products queued; `wp orillacart jobs run` builds them now.', $this->indexing->queueEverything()));
    }
}
