<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DateImmutableType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\SmallIntType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\Tools\SchemaTool;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\Doctrine\MigrationsFactory;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside wp-env, where the plugin is active on a real WordPress and MySQL.
 */
final class PluginDatabaseTest extends TestCase
{
    private const string OTHER_PLUGIN_TABLE = 'other_plugin_table';

    private Kernel $kernel;
    private DatabaseConfig $database;
    private Connection $connection;

    /** Where ids start after a test dropped the tables: past any the run used before. */
    private static int $ids = 0;

    protected function setUp(): void
    {
        $context = new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION);
        $this->kernel = new Kernel($context);
        $this->database = DatabaseConfig::fromWordPress();
        $this->connection = new EntityManagerFactory($this->database, $context)->create()->getConnection();
    }

    protected function tearDown(): void
    {
        $this->wpdb()->query('DROP TABLE IF EXISTS ' . $this->wpdb()->prefix . self::OTHER_PLUGIN_TABLE);
        // Leave the schema as activation left it for the next test.
        $this->migrator()->migrate();
        // The plugin running these tests keeps one Doctrine session for the whole run, which still holds rows of
        // the tables dropped here: new rows must not take their ids, or it refuses them as already known.
        self::$ids += 1_000_000;
        $tables = $this->connection->fetchFirstColumn(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? AND AUTO_INCREMENT IS NOT NULL',
            [addcslashes($this->database->tablePrefix, '_%\\') . '%'],
        );
        foreach ($tables as $table) {
            if (\is_string($table) && preg_match('/^\w+$/', $table) === 1) {
                $this->connection->executeStatement(\sprintf('ALTER TABLE %s AUTO_INCREMENT = %d', $table, self::$ids));
            }
        }
    }

    public function testActivationCreatedTheMigrationsTable(): void
    {
        self::assertContains($this->database->tablePrefix . 'migrations', $this->connection->createSchemaManager()->listTableNames());
        self::assertEquals(249, $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $this->database->tablePrefix . 'countries'));
    }

    public function testDoctrineUsesTheWordPressDatabase(): void
    {
        self::assertSame($this->wpdb()->get_var('SELECT DATABASE()'), $this->connection->fetchOne('SELECT DATABASE()'));
    }

    public function testDoctrineSharesWordPressesConnectionAndItsTransactions(): void
    {
        $wpdb = $this->wpdb();
        self::assertSame($wpdb->__get('dbh'), $this->connection->getNativeConnection(), 'one connection, not two');

        $option = 'orillacart_test_' . uniqid();
        $this->connection->beginTransaction();
        update_option($option, 'kept', false);
        $this->connection->rollBack();
        wp_cache_delete($option, 'options');
        self::assertFalse(get_option($option), 'what WordPress wrote in the shop\'s transaction went with it');

        // wpdb connects again after the server dropped it: Doctrine follows.
        $wpdb->close();
        $wpdb->db_connect();
        self::assertEquals(1, $this->connection->fetchOne('SELECT 1'));
        self::assertSame($wpdb->__get('dbh'), $this->connection->getNativeConnection());
    }

    public function testSchemaToolsSeeOnlyThePluginsTables(): void
    {
        $tables = $this->connection->createSchemaManager()->listTableNames();

        self::assertNotContains($this->wpdb()->posts, $tables);
        foreach ($tables as $table) {
            self::assertStringStartsWith($this->database->tablePrefix, $table);
        }
    }

    public function testTheMigrationsCreateExactlyTheMappedSchema(): void
    {
        $entityManager = new EntityManagerFactory($this->database, new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->create();
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        self::assertNotEmpty($metadata);
        // Doctrine Migrations keeps its own table, which no entity maps.
        $differences = array_filter(
            new SchemaTool($entityManager)->getUpdateSchemaSql($metadata),
            fn(string $sql): bool => $sql !== 'DROP TABLE ' . $this->database->tablePrefix . 'migrations',
        );
        self::assertSame([], array_values($differences), 'run npm run db:diff for a migration');
    }

    public function testFailedSqlThrowsAlthoughWordPressTurnedMysqliErrorsOff(): void
    {
        self::assertSame(MYSQLI_REPORT_OFF, new \mysqli_driver()->report_mode, 'WordPress turns mysqli errors off');

        try {
            $this->connection->executeStatement('INSERT INTO ' . $this->database->tablePrefix . 'countries (code) VALUES (NULL)');
            self::fail('A failed insert looked like a success.');
        } catch (DbalException $e) {
            self::assertStringContainsString('executing a query', $e->getMessage());
        }

        self::assertSame(MYSQLI_REPORT_OFF, new \mysqli_driver()->report_mode, 'wpdb still checks return values');
    }

    public function testUninstallDropsOnlyThePluginsTables(): void
    {
        $wpdb = $this->wpdb();
        $wpdb->query('CREATE TABLE ' . $wpdb->prefix . self::OTHER_PLUGIN_TABLE . ' (id INT)');
        $this->connection->executeStatement('CREATE TABLE ' . $this->database->tablePrefix . 'scratch (id INT)');

        $this->migrator()->uninstall();

        $remaining = $wpdb->get_col('SHOW TABLES');
        self::assertContains($wpdb->posts, $remaining);
        self::assertContains($wpdb->prefix . self::OTHER_PLUGIN_TABLE, $remaining);
        foreach ($remaining as $table) {
            self::assertIsString($table);
            self::assertStringStartsNotWith($this->database->tablePrefix, $table);
        }
    }

    public function testAChangedPluginVersionRunsTheUpgrade(): void
    {
        // A site whose files are newer than its database: here, one without any of the plugin's tables.
        update_option(PluginLifecycle::VERSION_OPTION, '1.0.0');
        $this->migrator()->uninstall();

        $lifecycle = $this->kernel->container()->get(PluginLifecycle::class);
        self::assertInstanceOf(PluginLifecycle::class, $lifecycle);
        $lifecycle->upgradeIfNeeded();

        self::assertSame(ORILLACART_VERSION, get_option(PluginLifecycle::VERSION_OPTION));
        self::assertContains($this->database->tablePrefix . 'migrations', $this->connection->createSchemaManager()->listTableNames());
        self::assertEquals(249, $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $this->database->tablePrefix . 'countries'));
    }

    public function testEachMigrationRunsOnTablesThatHoldRows(): void
    {
        $context = new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION);
        $fresh = function () use ($context): DatabaseMigrator {
            $entityManager = new EntityManagerFactory($this->database, $context)->create();

            return new DatabaseMigrator(new MigrationsFactory($entityManager, $this->database, $context)->create(), $entityManager);
        };
        $entityManager = new EntityManagerFactory($this->database, $context)->create();
        $migrations = new MigrationsFactory($entityManager, $this->database, $context)->create();
        $this->migrator()->uninstall();
        $migrations->getMetadataStorage()->ensureInitialized();
        $configuration = new MigratorConfiguration()->setAllOrNothing(false);

        try {
            foreach ($migrations->getMigrationRepository()->getMigrations()->getItems() as $migration) {
                // A shop upgrading has rows in every table: a column added without a default, or a data change
                // that reads them, has to cope.
                $this->fillEmptyTables();
                $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion($migration->getVersion());
                $migrations->getMigrator()->migrate($plan, $configuration);
            }
            self::assertSame([], new DatabaseMigrator($migrations, $entityManager)->pending());
            foreach (['orders', 'order_lines', 'products', 'refunds', 'download_grants'] as $table) {
                self::assertTrue($this->holdsRows($this->database->tablePrefix . $table), "{$table} held rows through the migrations after it was made");
            }
        } finally {
            // The other tests start from the plugin's own tables, empty.
            $fresh()->uninstall();
            $fresh()->migrate();
        }
    }

    /** One row in each of the plugin's tables that has none, with a plain value in every column that needs one. */
    private function fillEmptyTables(): void
    {
        $schema = $this->connection->createSchemaManager();
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($schema->listTableNames() as $table) {
                if ($table === $this->database->tablePrefix . 'migrations' || $this->holdsRows($table)) {
                    continue;
                }
                $row = [];
                foreach ($schema->listTableColumns($table) as $column) {
                    if ($column->getAutoincrement() || !$column->getNotnull() || $column->getDefault() !== null) {
                        continue;
                    }
                    $row[$this->connection->quoteSingleIdentifier($column->getName())] = match (true) {
                        $column->getType() instanceof IntegerType, $column->getType() instanceof BigIntType, $column->getType() instanceof SmallIntType, $column->getType() instanceof BooleanType => 1,
                        $column->getType() instanceof DecimalType => '1',
                        $column->getType() instanceof DateTimeType, $column->getType() instanceof DateTimeImmutableType => '2030-01-01 00:00:00',
                        $column->getType() instanceof DateType, $column->getType() instanceof DateImmutableType => '2030-01-01',
                        $column->getType() instanceof TextType, $column->getType() instanceof JsonType => '[]',
                        default => substr('x' . bin2hex(random_bytes(8)), 0, max(1, min(17, $column->getLength() ?? 17))),
                    };
                }
                $this->connection->insert($table, $row);
            }
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function holdsRows(string $table): bool
    {
        return $this->connection->fetchOne("SELECT 1 FROM {$table} LIMIT 1") !== false;
    }

    private function migrator(): DatabaseMigrator
    {
        $migrator = $this->kernel->container()->get(DatabaseMigrator::class);
        self::assertInstanceOf(DatabaseMigrator::class, $migrator);

        return $migrator;
    }

    private function wpdb(): \wpdb
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        self::assertInstanceOf(\wpdb::class, $wpdb);

        return $wpdb;
    }
}
