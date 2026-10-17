<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\Tools\SchemaTool;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
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
