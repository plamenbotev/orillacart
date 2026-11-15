<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Event\TestSuite\Finished;
use PHPUnit\Event\TestSuite\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Each integration test class leaves the site's database as it found it, so no class depends on the ones before
 * it, and the end-to-end tests after the suite open the shop the suite started with.
 *
 * Before the first class, with the plugin activated, every table is copied to a database of its own (the site's
 * name with `_kept`); after each class, a table whose checksum changed is put back row for row, and a table the
 * class made is dropped. A class may so clear a table to count what it makes: the rows it cleared come back.
 * Files and hooks are the classes' own to undo (RestTestCase uninstalls the extensions a class installed).
 */
final class KeptDatabase implements Extension
{
    /** @var array<string, string> each kept table's checksum */
    private static array $kept = [];

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        // Unit tests never load WordPress: nothing to keep.
        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof \wpdb) {
            return;
        }
        $facade->registerSubscribers(
            new class implements ExecutionStartedSubscriber {
                public function notify(ExecutionStarted $event): void
                {
                    KeptDatabase::keep();
                }
            },
            new class implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    if ($event->testSuite()->isForTestClass()) {
                        KeptDatabase::restore();
                    }
                }
            },
        );
    }

    public static function keep(): void
    {
        // Activated first: the tables and rows activation adds are part of what every class starts with.
        $lifecycle = new Kernel(new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->container()->get(PluginLifecycle::class);
        \assert($lifecycle instanceof PluginLifecycle);
        $lifecycle->activate();

        $wpdb = self::wpdb();
        $kept = self::database();
        $wpdb->query("DROP DATABASE IF EXISTS `{$kept}`");
        $wpdb->query("CREATE DATABASE `{$kept}`");
        self::$kept = [];
        foreach (self::tables() as $table) {
            $wpdb->query("CREATE TABLE `{$kept}`.`{$table}` LIKE `{$table}`");
            $wpdb->query("INSERT INTO `{$kept}`.`{$table}` SELECT * FROM `{$table}`");
            self::$kept[$table] = self::checksum($table);
        }
    }

    public static function restore(): void
    {
        if (self::$kept === []) {
            return;
        }
        $wpdb = self::wpdb();
        $kept = self::database();
        $tables = self::tables();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (array_diff($tables, array_keys(self::$kept)) as $made) {
                $wpdb->query("DROP TABLE `{$made}`");
            }
            foreach (self::$kept as $table => $checksum) {
                if (!\in_array($table, $tables, true)) {
                    $wpdb->query("CREATE TABLE `{$table}` LIKE `{$kept}`.`{$table}`");
                } elseif (self::checksum($table) === $checksum) {
                    continue;
                }
                $wpdb->query("DELETE FROM `{$table}`");
                $wpdb->query("INSERT INTO `{$table}` SELECT * FROM `{$kept}`.`{$table}`");
            }
        } finally {
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        // Options and users read before are read again.
        wp_cache_flush();
    }

    /** @return list<string> the site database's tables */
    private static function tables(): array
    {
        return array_values(array_filter(self::wpdb()->get_col("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"), \is_string(...)));
    }

    private static function checksum(string $table): string
    {
        $row = self::wpdb()->get_row("CHECKSUM TABLE `{$table}`", ARRAY_N);

        $sum = \is_array($row) ? ($row[1] ?? null) : null;

        return \is_scalar($sum) ? (string) $sum : '';
    }

    private static function database(): string
    {
        $name = self::wpdb()->get_var('SELECT DATABASE()');
        \assert(\is_string($name) && $name !== '');

        return $name . '_kept';
    }

    private static function wpdb(): \wpdb
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        \assert($wpdb instanceof \wpdb);

        return $wpdb;
    }
}
