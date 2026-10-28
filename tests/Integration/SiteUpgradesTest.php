<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Upgrades\SiteUpgrades;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside wp-env: a site brought up to the files' build after an update, under its lock. The network's walk
 * needs a multisite, which wp-env is not; it was tried on a network of four sites by hand.
 */
final class SiteUpgradesTest extends TestCase
{
    private PluginContext $context;
    private SiteUpgrades $upgrades;
    private string $migrations;

    protected function setUp(): void
    {
        $this->context = new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION);
        $this->upgrades = new SiteUpgrades($this->context);
        $database = DatabaseConfig::fromWordPress();
        $this->migrations = $database->tablePrefix . 'migrations';
    }

    protected function tearDown(): void
    {
        update_option(PluginLifecycle::VERSION_OPTION, $this->context->build);
        delete_option(PluginLifecycle::UPGRADE_FAILED_OPTION);
    }

    public function testASiteBehindIsUpgradedOnceAndThenIsCurrent(): void
    {
        update_option(PluginLifecycle::VERSION_OPTION, '1.0.0');

        $outcome = $this->upgrades->upgradeHere(new Kernel($this->context));

        self::assertTrue($outcome->isUpgraded());
        self::assertSame($this->context->build, get_option(PluginLifecycle::VERSION_OPTION));
        self::assertTrue($this->upgrades->upgradeHere(new Kernel($this->context))->isCurrent());
        self::assertSame([$this->context->build], array_values($this->upgrades->shops()));
        self::assertSame([], $this->upgrades->behind());
    }

    public function testAnotherRequestUpgradingTheSiteIsLeftToIt(): void
    {
        update_option(PluginLifecycle::VERSION_OPTION, '1.0.0');
        $wpdb = $GLOBALS['wpdb'] ?? null;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $lock = 'orillacart_upgrade_' . $wpdb->prefix;
        // Another database session than WordPress's (which the shop shares), as another request would have.
        $params = DatabaseConfig::fromWordPress()->connectionParams();
        $other = new \mysqli($params['host'], $params['user'], $params['password'], $params['dbname'], $params['port'] ?? null, $params['unix_socket'] ?? null);
        try {
            $locked = $other->query(\sprintf("SELECT GET_LOCK('%s', 0)", $other->real_escape_string($lock)));
            self::assertInstanceOf(\mysqli_result::class, $locked);
            self::assertSame('1', $locked->fetch_row()[0] ?? null);
            self::assertTrue($this->upgrades->upgradeHere(new Kernel($this->context), wait: 1)->isBusy());
            self::assertSame('1.0.0', get_option(PluginLifecycle::VERSION_OPTION));
        } finally {
            // Released by name: a closed session's locks go only once MySQL has seen it leave, which may be later.
            $other->query(\sprintf("SELECT RELEASE_LOCK('%s')", $other->real_escape_string($lock)));
            $other->close();
        }
        self::assertTrue($this->upgrades->upgradeHere(new Kernel($this->context))->isUpgraded());
    }

    public function testAMigrationThatFailsIsKeptForTheAdminAndTheMigrationsRunAgainWhenForced(): void
    {
        // The newest migration forgotten while its tables stay: running it again fails.
        $connection = new EntityManagerFactory(DatabaseConfig::fromWordPress(), $this->context)->create()->getConnection();
        $newest = $connection->fetchAssociative(\sprintf('SELECT * FROM %s ORDER BY version DESC LIMIT 1', $this->migrations));
        self::assertIsArray($newest);
        $connection->delete($this->migrations, ['version' => $newest['version']]);
        update_option(PluginLifecycle::VERSION_OPTION, '1.0.0');
        $log = ini_set('error_log', '/dev/null');
        try {
            $outcome = $this->upgrades->upgradeHere(new Kernel($this->context));

            self::assertTrue($outcome->isFailed());
            self::assertStringContainsString('already exists', $outcome->error);
            $failed = get_option(PluginLifecycle::UPGRADE_FAILED_OPTION);
            self::assertIsArray($failed);
            self::assertSame($this->context->build, $failed['version']);
            self::assertSame('1.0.0', get_option(PluginLifecycle::VERSION_OPTION));
        } finally {
            $connection->insert($this->migrations, $newest);
        }

        // WP-CLI runs the migrations even when the site says it is at the build; a success clears the failure.
        update_option(PluginLifecycle::VERSION_OPTION, $this->context->build);
        self::assertTrue($this->upgrades->upgradeHere(new Kernel($this->context), force: true)->isCurrent());
        self::assertFalse(get_option(PluginLifecycle::UPGRADE_FAILED_OPTION));
    }
}
