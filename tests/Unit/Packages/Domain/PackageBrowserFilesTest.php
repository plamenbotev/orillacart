<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages\Domain;

use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Packages\Domain\PackageRuleViolation;
use OrillaCart\Packages\Infrastructure\Doctrine\DoctrinePackageTemplates;
use OrillaCart\Platform\Twig\PackageTemplateLoader;
use PHPUnit\Framework\TestCase;

/** A package's paths are lowercase; every file a browser could get as HTML is a browser file, and only templates render. */
final class PackageBrowserFilesTest extends TestCase
{
    public function testAPathIsLowercase(): void
    {
        foreach (['Evil.HTML.TWIG', 'templates/Box.html.twig', 'Main.ocs', 'README.md', "main.ocs\n"] as $wrong) {
            try {
                Package::checkPath($wrong);
                self::fail($wrong);
            } catch (PackageRuleViolation $e) {
                self::assertSame('orillacart_invalid_extension_file', $e->errorCode, $wrong);
            }
        }
        Package::checkPath('templates/notes.md');
        Package::checkPath('readme.md');
        $this->addToAssertionCount(2);
    }

    public function testEveryFileUnderTemplatesAndEveryTwigFileIsABrowserFile(): void
    {
        $at = new \DateTimeImmutable('2026-11-10');
        $package = new Package('trade', 'Trade', $at);
        $package->write('main.ocs', 'extension "trade" {}', $at);
        $package->write('readme.md', '# Trade', $at);
        $package->write('templates/evil.md', '<script>1</script>', $at);
        $package->write('mail/box.html.twig', '<p>1</p>', $at);
        $package->write('assets/notes.md', '<script>1</script>', $at);

        self::assertSame(['assets/notes.md', 'mail/box.html.twig', 'templates/evil.md'], $package->browserFiles(), 'by path');
    }

    public function testOnlyATemplatesNameRenders(): void
    {
        $at = new \DateTimeImmutable('2026-11-10');
        $package = new Package('trade', 'Trade', $at);
        $package->write('main.ocs', 'extension "trade" {}', $at);
        $package->write('templates/evil.md', '<script>1</script>', $at);
        $package->write('templates/box.html.twig', '<p>1</p>', $at);
        $package->write('templates/cart/totals.html.twig', '<p>totals</p>', $at);
        $package->enable([], ['templates'], $at);
        $templates = new DoctrinePackageTemplates(new class ($package) implements PackageRepository {
            public function __construct(private readonly Package $package) {}

            public function all(): array
            {
                return [$this->package];
            }

            public function enabled(): array
            {
                return [$this->package];
            }

            public function find(string $slug): ?Package
            {
                return $slug === $this->package->slug() ? $this->package : null;
            }

            public function save(Package $package): void {}

            public function remove(Package $package): void {}
        });
        $loader = new PackageTemplateLoader($templates);

        self::assertTrue($loader->exists('x/trade/box.html.twig'));
        self::assertTrue($loader->exists('cart/totals.html.twig'), 'an override of the shop\'s');
        self::assertNull($templates->source('x/trade/evil.md'));
        self::assertNull($templates->source('evil.md'));
        self::assertFalse($loader->exists('x/trade/evil.md'));
        self::assertFalse($loader->exists('x/trade/BOX.html.twig'));
        $this->expectException(\Twig\Error\LoaderError::class);
        $loader->getSourceContext('x/trade/evil.md');
    }
}
