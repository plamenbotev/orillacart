<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages\Domain;

use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageState;
use PHPUnit\Framework\TestCase;

/** An enabled extension runs the files it was enabled with: an edit waits for a manager to enable it again. */
final class PackageRunningFilesTest extends TestCase
{
    public function testAnEditReachesNoOneUntilEnabledAgain(): void
    {
        $at = new \DateTimeImmutable('2026-11-10 12:00:00');
        $package = new Package('trade', 'Trade', $at);
        $package->write('main.ocs', 'extension "trade" { name: "Trade" }', $at);
        $package->write('assets/app.js', 'console.log(1)', $at);
        $package->write('templates/box.html.twig', '<p>1</p>', $at);

        self::assertSame('extension "trade" { name: "Trade" }', $package->runningSource(), 'a draft runs its files as they are');
        $package->enable([], ['assets'], $at);

        $package->write('main.ocs', 'extension "trade" { name: "Trade", needs: { http: ["evil.example"] } }', $at);
        $package->write('assets/app.js', 'steal()', $at);
        $package->write('helpers.ocs', 'fn more() { return 1 }', $at);
        $package->removeFile('templates/box.html.twig', $at);

        self::assertTrue($package->isStale());
        self::assertSame('extension "trade" { name: "Trade" }', $package->runningSource());
        self::assertSame('console.log(1)', $package->runningFile('assets/app.js'));
        self::assertSame('<p>1</p>', $package->runningFile('templates/box.html.twig'), 'a removed file runs until enabled again');

        $package->enable([], ['assets'], $at);
        self::assertFalse($package->isStale());
        self::assertSame('steal()', $package->runningFile('assets/app.js'));
        self::assertNull($package->runningFile('templates/box.html.twig'));
        self::assertStringContainsString('evil.example', $package->runningSource());

        $package->disable($at);
        self::assertStringContainsString('fn more()', $package->runningSource(), 'switched off, it is the files as they are');
    }

    public function testAStoppedExtensionKeepsTheFilesItWasEnabledWith(): void
    {
        $at = new \DateTimeImmutable('2026-11-10 12:00:00');
        $package = new Package('trade', 'Trade', $at);
        $package->write('main.ocs', 'route GET "/a" fn(request) { return 1 }', $at);
        $package->enable([], [], $at);
        $package->write('main.ocs', 'route GET "/b" fn(request) { return 2 }', $at);
        for ($i = 0; $i < Package::FAILURES_TO_STOP; ++$i) {
            $package->failed('broke', $at);
        }

        self::assertSame(PackageState::Stopped, $package->state());
        self::assertSame('route GET "/a" fn(request) { return 1 }', $package->runningSource(), 'its routes are the ones it ran, to answer that it is stopped');
    }

    public function testAnEditToATemplateOrAnAssetMakesItStaleAsAScriptsDoes(): void
    {
        $at = new \DateTimeImmutable('2026-11-10 12:00:00');
        $package = new Package('trade', 'Trade', $at);
        $package->write('main.ocs', 'extension "trade" { name: "Trade" }', $at);
        $package->write('templates/box.html.twig', '<p>1</p>', $at);
        $package->enable([], ['assets'], $at);
        self::assertFalse($package->isStale());

        $package->write('templates/box.html.twig', '<p>2</p>', $at);
        self::assertTrue($package->isStale(), 'a template edit waits for enabling and says so');
        $package->write('templates/box.html.twig', '<p>1</p>', $at);
        self::assertFalse($package->isStale(), 'back as enabled');

        $package->write('assets/app.js', 'console.log(1)', $at);
        self::assertTrue($package->isStale(), 'an added asset');
        $package->removeFile('assets/app.js', $at);
        $package->removeFile('templates/box.html.twig', $at);
        self::assertTrue($package->isStale(), 'a removed template');
    }
}
