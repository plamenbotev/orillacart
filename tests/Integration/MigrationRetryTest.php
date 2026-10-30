<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use Doctrine\DBAL\Connection;
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
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\Doctrine\MigrationsFactory;
use OrillaCart\Platform\PluginContext;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside wp-env. MySQL commits each DDL statement by itself, so a migration of several statements cut off
 * halfway (a lock wait, a lost connection) leaves part of its work done and runs again from the start: every
 * such migration guards its statements, so the retry goes on past what is there instead of failing on it.
 * The furthest a cut can leave a migration is all of its work done and its row not written: run again there,
 * it must complete and change nothing.
 */
final class MigrationRetryTest extends TestCase
{
    /** The migrations of more than one statement, in order. */
    private const array MULTI_STATEMENT = [
        '20261017133557', '20261017133600', '20261017171353', '20261017192836', '20261017202550', '20261017232751',
        '20261018002058', '20261018002647', '20261018003024', '20261018004837', '20261018070800', '20261018075953',
        '20261018081200', '20261018101729', '20261018114340', '20261018120446', '20261018122341', '20261018124246',
        '20261018141653', '20261018143458', '20261018183857', '20261018211015', '20261018221430', '20261018222546',
        '20261019110001', '20261019122551', '20261019153839', '20261019154633', '20261020032258', '20261020075404',
        '20261020092307', '20261020093549', '20261020094938', '20261020100703', '20261020103150', '20261021063721',
        '20261021112908', '20261021114443', '20261021120017', '20261021194615', '20261021210000', '20261022061626',
        '20261022063949', '20261022070509', '20261023071331', '20261023075434', '20261023080700', '20261023090222',
        '20261023090902', '20261023092730', '20261023120051', '20261024072028', '20261026074157', '20261029072129',
        '20261029074740', '20261029191124', '20261030093000', '20261030112606', '20261030150100', '20261030160000', '20261030162000',
    ];

    private PluginContext $context;
    private DatabaseConfig $database;
    private Connection $connection;
    /** Where ids start after the test dropped the tables: past any the run used before (see PluginDatabaseTest). */
    private int $ids = 0;

    protected function setUp(): void
    {
        $this->context = new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION);
        $this->database = DatabaseConfig::fromWordPress();
        $this->connection = new EntityManagerFactory($this->database, $this->context)->create()->getConnection();
        foreach ($this->autoIncrements() as $next) {
            $this->ids = max($this->ids, $next);
        }
    }

    protected function tearDown(): void
    {
        // The plugin running these tests keeps one Doctrine session for the whole run, which still holds rows of
        // the tables dropped here: new rows must not take their ids, or it refuses them as already known.
        $this->ids += 1_000_000;
        foreach (array_keys($this->autoIncrements()) as $table) {
            if (preg_match('/^\w+$/', $table) === 1) {
                $this->connection->executeStatement(\sprintf('ALTER TABLE %s AUTO_INCREMENT = %d', $table, $this->ids));
            }
        }
    }

    public function testEveryMultiStatementMigrationRunsAgainAfterItselfAndChangesNothing(): void
    {
        $entityManager = new EntityManagerFactory($this->database, $this->context)->create();
        $migrations = new MigrationsFactory($entityManager, $this->database, $this->context)->create();
        $table = $this->database->tablePrefix . 'migrations';
        $this->fresh()->uninstall();
        $migrations->getMetadataStorage()->ensureInitialized();
        $configuration = new MigratorConfiguration()->setAllOrNothing(false);

        try {
            foreach ($migrations->getMigrationRepository()->getMigrations()->getItems() as $migration) {
                $version = (string) $migration->getVersion();
                // Rows in every table, so the data statements have work to do, and to do only once.
                $this->fillEmptyTables();
                $migrations->getMigrator()->migrate($migrations->getMigrationPlanCalculator()->getPlanUntilVersion($migration->getVersion()), $configuration);
                if (!\in_array(substr($version, -14), self::MULTI_STATEMENT, true)) {
                    continue;
                }

                // Its work all done but its row lost to the cut: run again, every statement finds its work done.
                // A fresh set of migrations, as the retry is another request: Doctrine keeps the SQL a migration
                // object queued, so the same object run twice would run its first SQL again.
                $before = $this->snapshot();
                self::assertSame(1, $this->connection->delete($table, ['version' => $version]));
                $retry = $this->dependencies();
                $plan = $retry->getMigrationPlanCalculator()->getPlanUntilVersion($migration->getVersion());
                self::assertCount(1, $plan->getItems(), "{$version} alone runs again");
                $retry->getMigrator()->migrate($plan, $configuration);

                self::assertSame($before, $this->snapshot(), "{$version} run again changed the schema or the rows");
            }
            self::assertSame([], new DatabaseMigrator($migrations, $entityManager)->pending());
        } finally {
            // The other tests start from the plugin's own tables, empty.
            $this->fresh()->uninstall();
            $this->fresh()->migrate();
        }
    }

    public function testTheMigrationsCoveredAreTheOnesOfSeveralStatements(): void
    {
        // The list above is kept by hand: a new migration of several statements joins it, with its statements guarded.
        $found = [];
        $files = glob(\dirname(__DIR__, 2) . '/migrations/Version*.php');
        self::assertNotFalse($files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $up = strpos($source, 'function up(');
            $down = strpos($source, 'function down(');
            self::assertNotFalse($up);
            self::assertNotFalse($down);
            $body = substr($source, $up, $down - $up);
            if (substr_count($body, '$this->addSql(') + substr_count($body, '$this->insert(') > 1) {
                $found[] = substr(basename($file, '.php'), \strlen('Version'));
            }
        }
        sort($found);

        self::assertSame($found, self::MULTI_STATEMENT);
    }

    /**
     * Each of the plugin's tables: its definition and a checksum of its rows. The migrations' own table changes
     * with every run.
     *
     * @return array<string, array{string, mixed}>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->connection->createSchemaManager()->listTableNames() as $table) {
            if ($table === $this->database->tablePrefix . 'migrations') {
                continue;
            }
            $create = $this->connection->fetchNumeric('SHOW CREATE TABLE ' . $table);
            self::assertIsArray($create);
            self::assertIsString($create[1]);
            // The next id moves on as rows come and go; the definition is the rest.
            $definition = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $create[1]);
            $checksum = $this->connection->fetchNumeric('CHECKSUM TABLE ' . $table);
            self::assertIsArray($checksum);
            $snapshot[$table] = [$definition, $checksum[1]];
        }

        return $snapshot;
    }

    /** One row in each of the plugin's tables that has none, with a plain value in every column that needs one. */
    private function fillEmptyTables(): void
    {
        $schema = $this->connection->createSchemaManager();
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($schema->listTableNames() as $table) {
                if ($table === $this->database->tablePrefix . 'migrations' || $this->connection->fetchOne("SELECT 1 FROM {$table} LIMIT 1") !== false) {
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

    /**
     * @return array<string, int> the next id of each of the plugin's tables, by table
     */
    private function autoIncrements(): array
    {
        $next = [];
        foreach ($this->connection->fetchAllNumeric(
            'SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? AND AUTO_INCREMENT IS NOT NULL',
            [addcslashes($this->database->tablePrefix, '_%\\') . '%'],
        ) as [$table, $id]) {
            if (\is_string($table) && is_numeric($id)) {
                $next[$table] = (int) $id;
            }
        }

        return $next;
    }

    private function dependencies(): DependencyFactory
    {
        $entityManager = new EntityManagerFactory($this->database, $this->context)->create();

        return new MigrationsFactory($entityManager, $this->database, $this->context)->create();
    }

    private function fresh(): DatabaseMigrator
    {
        $entityManager = new EntityManagerFactory($this->database, $this->context)->create();

        return new DatabaseMigrator(new MigrationsFactory($entityManager, $this->database, $this->context)->create(), $entityManager);
    }
}
