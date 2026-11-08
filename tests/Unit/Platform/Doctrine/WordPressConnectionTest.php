<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Doctrine;

use OrillaCart\Platform\Doctrine\WordPressConnection;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Design 54: Doctrine borrows the handle WordPress holds for this site's tables. Core wpdb has one; a sharding
 * drop-in has one per server and is asked which holds the shop's tables.
 */
#[RunTestsInSeparateProcesses]
#[RequiresPhpExtension('mysqli')]
final class WordPressConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        // Unit tests run without WordPress: core wpdb's shape, then the drop-ins', which extend it.
        if (!class_exists(\wpdb::class, false)) {
            require_once \dirname(__DIR__, 3) . '/Fixtures/Database/wpdb.php';
        }
        require_once \dirname(__DIR__, 3) . '/Fixtures/Database/hyperdb.php';
        require_once \dirname(__DIR__, 3) . '/Fixtures/Database/LudicrousDB.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testCoreWpdbSharesItsOneHandleAsItIsThen(): void
    {
        $wpdb = new \wpdb('wp', 'secret', 'wordpress', 'db');
        $first = self::handleOf();
        $this->handle($wpdb, $first);
        $GLOBALS['wpdb'] = $wpdb;
        $connection = new WordPressConnection();

        self::assertTrue(WordPressConnection::available());
        self::assertSame($first, $connection->getNativeConnection());

        // wpdb connected again (the server dropped a worker's connection): the new handle is taken.
        $again = self::handleOf();
        $this->handle($wpdb, $again);
        self::assertSame($again, $connection->getNativeConnection());
    }

    public function testWithoutWordPressThereIsNothingToShare(): void
    {
        self::assertFalse(WordPressConnection::available());
    }

    public function testAShardingDropInIsAskedWhichServerHoldsTheSitesTablesAsForAWrite(): void
    {
        $wpdb = new \hyperdb();
        $tenant = self::handleOf();
        $other = self::handleOf();
        $wpdb->answer = $tenant;
        $wpdb->dbhs = ['global__w' => $other, 'tenant3__w' => $tenant];
        // dbh is only the last server wpdb used: here the global one.
        $this->handle($wpdb, $other);
        $GLOBALS['wpdb'] = $wpdb;
        $connection = new WordPressConnection();

        self::assertSame($tenant, $connection->getNativeConnection());
        self::assertSame(['UPDATE wp_3_orillacart_orders SET id = id WHERE 0'], $wpdb->asked);
    }

    public function testTheHandleIsPinnedWhileTheDropInKeepsItOpen(): void
    {
        $wpdb = new \hyperdb();
        $tenant = self::handleOf();
        $wpdb->answer = $tenant;
        $wpdb->dbhs = ['tenant3__w' => $tenant];
        $GLOBALS['wpdb'] = $wpdb;
        $connection = new WordPressConnection();

        $connection->getNativeConnection();
        $connection->getNativeConnection();
        self::assertCount(1, $wpdb->asked, 'asked once, then kept: a transaction stays on one server');

        // The drop-in closed it (idle too long in a worker) and would open another: asked again.
        $reopened = self::handleOf();
        $wpdb->dbhs = [];
        $wpdb->answer = $reopened;
        self::assertSame($reopened, $connection->getNativeConnection());
        self::assertCount(2, $wpdb->asked);
    }

    public function testLudicrousDbKeepsCoresFirstParameter(): void
    {
        $wpdb = new \LudicrousDB();
        $tenant = self::handleOf();
        $wpdb->answer = $tenant;
        $GLOBALS['wpdb'] = $wpdb;

        self::assertSame($tenant, new WordPressConnection()->getNativeConnection());
        self::assertSame([[true, 'UPDATE wp_3_orillacart_orders SET id = id WHERE 0']], $wpdb->asked);
    }

    public function testADropInThatNamesNoServerIsAnError(): void
    {
        $wpdb = new \LudicrousDB();
        $wpdb->answer = false;
        $GLOBALS['wpdb'] = $wpdb;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('named no server for the tables wp_3_orillacart_*');
        new WordPressConnection()->getNativeConnection();
    }

    /** A mysqli handle that is connected to nothing: enough to tell one from another. */
    private static function handleOf(): \mysqli
    {
        $handle = mysqli_init();

        return $handle === false ? throw new \RuntimeException('mysqli_init() failed.') : $handle;
    }

    /** wpdb's protected handle, as wpdb sets it when it connects. */
    private function handle(\wpdb $wpdb, \mysqli $mysqli): void
    {
        new \ReflectionProperty(\wpdb::class, 'dbh')->setValue($wpdb, $mysqli);
    }
}
