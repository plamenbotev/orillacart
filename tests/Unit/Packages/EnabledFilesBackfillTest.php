<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Migrations\Version20261110171500;
use OrillaCart\Packages\Domain\Package;
use PHPUnit\Framework\TestCase;

/** The backfill of an extension's enabled files tells unchanged files from changed ones as the package does. */
final class EnabledFilesBackfillTest extends TestCase
{
    public function testTheChecksumIsThePackages(): void
    {
        $at = new \DateTimeImmutable('2026-11-10 12:00:00');
        $package = new Package('trade', 'Trade', $at);
        $package->write('b.ocs', 'fn two() { return 2 }', $at);
        $package->write('main.ocs', 'extension "trade" { name: "Trade" }', $at);
        $package->write('templates/box.html.twig', '<p>1</p>', $at);
        $rows = [
            ['path' => 'b.ocs', 'content' => 'fn two() { return 2 }'],
            ['path' => 'main.ocs', 'content' => 'extension "trade" { name: "Trade" }'],
            ['path' => 'templates/box.html.twig', 'content' => '<p>1</p>'],
        ];

        $snapshot = Version20261110171500::snapshot($rows);

        self::assertSame([['b.ocs', 'fn two() { return 2 }'], ['main.ocs', 'extension "trade" { name: "Trade" }'], ['templates/box.html.twig', '<p>1</p>']], $snapshot);
        self::assertSame($package->checksum(), Version20261110171500::checksum($snapshot));
        $snapshot[0][1] = 'fn two() { return 3 }';
        self::assertNotSame($package->checksum(), Version20261110171500::checksum($snapshot));
    }
}
