<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Plugin;
use OrillaCart\Shared\Persistence\LoadedEntities;
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
 * name with `_kept`); after each class, a table whose checksum changed is put back row for row, a table the
 * class dropped is made again with its foreign keys, and a table the class made is dropped. A class may so clear
 * a table to count what it makes: the rows it cleared come back. A statement that fails stops the run (run()):
 * nothing is emptied that cannot be filled again.
 *
 * The tables' counters are left where the classes moved them, on purpose: the plugin's kernel lives as long as
 * the process and remembers products, prices and pages by id, so a class given the ids of the one before it
 * would be shown that one's lamp for its hoodie. No test may therefore expect an id, only the ids it was given.
 * Only the database WordPress is connected to is kept: on a site whose tables lie in several (a sharded network,
 * LudicrousDB), the others are not, and keep() says so. Files and hooks are the classes' own to undo (RestTestCase uninstalls the extensions a class installed).
 */
final class KeptDatabase implements Extension
{
    /** @var array<string, string> each kept table's checksum */
    private static array $kept = [];

    /** @var array<string, string> the statement that makes each kept table, as the site had it */
    private static array $made = [];

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

        $kept = self::database();
        if (self::wpdb()::class !== \wpdb::class) {
            fwrite(\STDERR, \sprintf("\nKeptDatabase: the site reads its tables through %s; only `%s` is kept and put back, tables in other databases are not.\n", self::wpdb()::class, substr($kept, 0, -5)));
        }
        self::$kept = [];
        self::$made = [];
        self::run("DROP DATABASE IF EXISTS `{$kept}`");
        self::run("CREATE DATABASE `{$kept}`");
        foreach (self::tables() as $table) {
            self::run("CREATE TABLE `{$kept}`.`{$table}` LIKE `{$table}`");
            self::run("INSERT INTO `{$kept}`.`{$table}` SELECT * FROM `{$table}`");
            // As the site has it, foreign keys and all: the copy, made LIKE it, has none.
            $made = self::wpdb()->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            $statement = \is_array($made) ? ($made[1] ?? null) : null;
            if (!\is_string($statement) || $statement === '') {
                self::stop("SHOW CREATE TABLE `{$table}`");
            }
            self::$made[$table] = $statement;
        }
        // Last, and only once every table was copied: restore() works from this list alone.
        foreach (array_keys(self::$made) as $table) {
            self::$kept[$table] = self::checksum($table);
        }
    }

    public static function restore(): void
    {
        if (self::$kept === []) {
            return;
        }
        $kept = self::database();
        $tables = self::tables();
        self::run('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_diff($tables, array_keys(self::$kept)) as $made) {
            self::run("DROP TABLE `{$made}`");
        }
        foreach (self::$kept as $table => $checksum) {
            if (!\in_array($table, $tables, true)) {
                self::run(self::$made[$table]);
            } elseif (self::checksum($table) === $checksum) {
                continue;
            }
            // Together or not at all: a refill that fails leaves the rows the class left, not an empty table.
            self::run('START TRANSACTION');
            self::run("DELETE FROM `{$table}`", 'ROLLBACK');
            self::run("INSERT INTO `{$table}` SELECT * FROM `{$kept}`.`{$table}`", 'ROLLBACK');
            self::run('COMMIT');
        }
        self::run('SET FOREIGN_KEY_CHECKS = 1');
        // Options and users read before are read again, and so are the shop's entities: the plugin's kernel lives
        // as long as the process, and what it loaded for one class is not what the next class finds in the tables
        // (an id given again is another row).
        wp_cache_flush();
        $container = Plugin::booted()?->container();
        $loaded = $container?->has(LoadedEntities::class) === true ? $container->get(LoadedEntities::class) : null;
        if ($loaded instanceof LoadedEntities) {
            $loaded->forget();
        }
    }

    /**
     * Runs the statement, or stops the whole run: a copy that failed would have restore() empty the site's tables
     * and put nothing back, and a restore that failed leaves the classes after it, and the end-to-end tests,
     * another shop than the one they expect. PHPUnit turns a subscriber's exception into a warning and goes on,
     * so this ends the process instead.
     */
    private static function run(string $statement, ?string $undo = null): void
    {
        if (self::wpdb()->query($statement) !== false) {
            return;
        }
        $error = self::wpdb()->last_error;
        if ($undo !== null) {
            self::wpdb()->query($undo);
        }
        self::stop($statement, $error);
    }

    private static function stop(string $statement, string $error = ''): never
    {
        // Nothing more is restored from a copy that cannot be trusted.
        self::$kept = [];
        fwrite(\STDERR, \sprintf("\nKeptDatabase: the tests stop here, the site's database could not be kept or put back.\n  %s\n  %s\nThe database user needs CREATE and DROP on `%s`.\n", $statement, $error === '' ? '(no answer)' : $error, self::database()));
        exit(1);
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
