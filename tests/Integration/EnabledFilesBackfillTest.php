<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Migrations\Version20261110171500;
use OrillaCart\Packages\Application\Dto\EnablePackage;
use OrillaCart\Packages\Application\Dto\InstallPackage;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\PluginContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Runs inside wp-env. An extension enabled before the shop kept its files gets them from the migration when its
 * files are still the ones it was enabled with, and is switched off when they changed since (PLAN 432).
 */
final class EnabledFilesBackfillTest extends TestCase
{
    private Connection $connection;
    private DatabaseConfig $database;
    private PackageService $packages;

    protected function setUp(): void
    {
        $context = new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION);
        $packages = new Kernel($context)->container()->get(PackageService::class);
        self::assertInstanceOf(PackageService::class, $packages);
        $this->packages = $packages;
        $this->database = DatabaseConfig::fromWordPress();
        $this->connection = new EntityManagerFactory($this->database, $context)->create()->getConnection();
    }

    protected function tearDown(): void
    {
        foreach (['backfill_same', 'backfill_changed'] as $slug) {
            $this->connection->executeStatement('DELETE FROM ' . $this->database->tablePrefix . 'packages WHERE slug = ?', [$slug]);
        }
    }

    public function testTheFilesAreKeptWhenUnchangedAndTheExtensionSwitchedOffWhenChanged(): void
    {
        $packages = $this->database->tablePrefix . 'packages';
        foreach (['backfill_same', 'backfill_changed'] as $slug) {
            // In path order, as the package holds them once read back.
            $this->packages->install(new InstallPackage($slug, 'Backfill', [
                'b.ocs' => 'fn two() { return 2 }',
                'main.ocs' => "extension \"{$slug}\" { name: \"Backfill\" }\nfn one() { return 1 }",
                'templates/box.html.twig' => '<p>box</p>',
            ]));
            $this->packages->enable($slug, new EnablePackage(), mayPublishHtml: true);
        }
        $this->connection->executeStatement("UPDATE {$packages} SET enabled_files = NULL WHERE slug IN ('backfill_same', 'backfill_changed')");
        $id = $this->connection->fetchOne("SELECT id FROM {$packages} WHERE slug = 'backfill_changed'");
        $this->connection->executeStatement('UPDATE ' . $this->database->tablePrefix . "package_files SET content = 'fn two() { return 3 }' WHERE package_id = ? AND path = 'b.ocs'", [$id]);

        $this->migrate();
        $this->migrate();

        $same = $this->connection->fetchAssociative("SELECT state, enabled_files FROM {$packages} WHERE slug = 'backfill_same'");
        self::assertIsArray($same);
        self::assertSame('enabled', $same['state']);
        self::assertSame([
            ['b.ocs', 'fn two() { return 2 }'],
            ['main.ocs', "extension \"backfill_same\" { name: \"Backfill\" }\nfn one() { return 1 }"],
            ['templates/box.html.twig', '<p>box</p>'],
        ], \is_string($same['enabled_files']) ? json_decode($same['enabled_files'], true) : null, 'path order, as enabling writes it');

        $changed = $this->connection->fetchAssociative("SELECT state, enabled_files, last_problem FROM {$packages} WHERE slug = 'backfill_changed'");
        self::assertIsArray($changed);
        self::assertSame(['disabled', null, Version20261110171500::CHANGED], [$changed['state'], $changed['enabled_files'], $changed['last_problem']]);
    }

    private function migrate(): void
    {
        $migration = new Version20261110171500($this->connection, new NullLogger());
        $migration->setTablePrefix($this->database->tablePrefix);
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), array_values($query->getParameters()));
        }
    }
}
