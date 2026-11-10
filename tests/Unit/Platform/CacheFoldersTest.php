<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform;

use OrillaCart\Platform\CacheFolders;
use PHPUnit\Framework\TestCase;

final class CacheFoldersTest extends TestCase
{
    private string $cache;
    private string $root;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/orillacart-cache-test-' . bin2hex(random_bytes(6));
        $this->root = $this->cache . '/orillacart';
        mkdir($this->root . '/twig/1.0.0', 0o755, true);
        chmod($this->cache, 0o755);
        chmod($this->root, 0o755);
        chmod($this->root . '/twig', 0o755);
        chmod($this->root . '/twig/1.0.0', 0o755);
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->cache, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->cache);
    }

    public function testTheCachesRootTellsTheWebServerToHandOutNothing(): void
    {
        CacheFolders::protect($this->root);

        self::assertStringContainsString('Require all denied', (string) file_get_contents($this->root . '/.htaccess'));
        self::assertStringContainsString('Deny from all', (string) file_get_contents($this->root . '/.htaccess'));
        self::assertStringContainsString('<deny users="*" />', (string) file_get_contents($this->root . '/web.config'));
        self::assertSame("<?php\n// Silence.\n", file_get_contents($this->root . '/index.php'));
    }

    public function testAFolderOnlyItsOwnerMayWriteToIsUsedAndReportsNothing(): void
    {
        self::assertTrue(CacheFolders::trusted($this->root . '/twig/1.0.0', $this->root));
        self::assertSame([], CacheFolders::looseFolders($this->root));
    }

    public function testAFolderEveryUserMayWriteToIsNotUsedWhereverItIsOnTheWay(): void
    {
        foreach ([$this->root . '/twig/1.0.0', $this->root . '/twig', $this->root, $this->cache] as $loose) {
            chmod($loose, 0o777);
            self::assertFalse(CacheFolders::trusted($this->root . '/twig/1.0.0', $this->root), $loose);
            self::assertSame(CacheFolders::WORLD, CacheFolders::looseFolders($this->root)[$loose] ?? null, $loose);
            chmod($loose, 0o755);
        }
    }

    public function testTheStickyBitPassesAboveTheCacheOnly(): void
    {
        chmod($this->cache, 0o1777);
        self::assertTrue(CacheFolders::trusted($this->root . '/twig/1.0.0', $this->root));
        chmod($this->cache, 0o755);

        chmod($this->root, 0o1777);
        self::assertFalse(CacheFolders::trusted($this->root . '/twig/1.0.0', $this->root));
    }

    public function testAFolderItsGroupMayWriteToIsUsedAndReported(): void
    {
        chmod($this->root . '/twig/1.0.0', 0o775);

        self::assertTrue(CacheFolders::trusted($this->root . '/twig/1.0.0', $this->root));
        self::assertSame([$this->root . '/twig/1.0.0' => CacheFolders::GROUP], CacheFolders::looseFolders($this->root));
    }

    public function testAFolderNotMadeYetIsPassedOver(): void
    {
        self::assertTrue(CacheFolders::trusted($this->root . '/container/abc', $this->root));
    }
}
