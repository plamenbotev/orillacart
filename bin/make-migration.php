<?php

/*
 * Writes a migration for the difference between the entity mapping and the database, after running the
 * pending migrations. Development only; run inside wp-env:
 *
 *   npm run db:diff
 *
 * The generated SQL names tables with the {prefix} placeholder, so the migration runs on any site.
 */

use Doctrine\Migrations\Generator\Exception\NoChangesDetected;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\Doctrine\Migration;
use OrillaCart\Platform\Doctrine\MigrationsFactory;
use OrillaCart\Platform\PluginContext;

$pluginFile = dirname(__DIR__) . '/orillacart.php';
$database = DatabaseConfig::fromWordPress();
$context = new PluginContext($pluginFile, ORILLACART_VERSION);
$entityManager = new EntityManagerFactory($database, $context)->create();
$migrations = new MigrationsFactory($entityManager, $database, $context)->create();

$executed = new DatabaseMigrator($migrations, $entityManager)->migrate();
if ($executed !== []) {
    WP_CLI::log('Ran the pending migrations first: ' . implode(', ', $executed));
}

$class = $migrations->getClassNameGenerator()->generateClassName(MigrationsFactory::MIGRATIONS_NAMESPACE);
try {
    $path = $migrations->getDiffGenerator()->generate($class, null, checkDbPlatform: false);
} catch (NoChangesDetected) {
    WP_CLI::success('The database matches the entity mapping; no migration needed.');

    return;
}

$code = (string) file_get_contents($path);
$code = str_replace(
    ['use Doctrine\Migrations\AbstractMigration;', 'extends AbstractMigration', $database->tablePrefix],
    ['use ' . Migration::class . ';', 'extends Migration', Migration::PREFIX],
    $code,
);
file_put_contents($path, $code);

WP_CLI::success('Wrote ' . basename($path) . '; review it, then run: wp orillacart migrate');
