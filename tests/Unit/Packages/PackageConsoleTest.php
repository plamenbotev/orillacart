<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Packages\Application\Dto\RunFunction;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * The console runs what a manager approved: an enabled extension's files as enabled with what was granted then, and
 * a draft's or a disabled one's saved files with nothing granted. Disabling forgets the grant.
 */
final class PackageConsoleTest extends TestCase
{
    private const string APPROVED = <<<'OCS'
        extension "peek" { name: "Peek", needs: { orders: [read] } }
        fn look() { return shop.orders.find(5) }
        fn secret() { return settings.get("api_secret") }
        OCS;

    private const string EDITED = <<<'OCS'
        extension "peek" { name: "Peek", needs: { orders: [read], customers: [read] } }
        fn look() { return shop.customers.find(5) }
        fn secret() { return settings.get("api_secret") }
        OCS;

    private Package $package;

    protected function setUp(): void
    {
        $at = new \DateTimeImmutable('2026-11-10');
        $this->package = new Package('peek', 'Peek', $at);
        $this->package->write('main.ocs', self::APPROVED . "\n", $at);
        $this->package->changeSettings(['api_secret' => 'stored-secret'], $at);
    }

    public function testAnEnabledExtensionsConsoleRunsTheFilesItWasEnabledWith(): void
    {
        $this->package->enable(['orders:read'], ['orders:read'], new \DateTimeImmutable('2026-11-10'));
        $this->package->write('main.ocs', self::EDITED . "\n", new \DateTimeImmutable('2026-11-10'));

        $run = $this->service()->run('peek', new RunFunction('look'));

        self::assertTrue($run->ok, (string) json_encode($run->failure));
        self::assertSame('order 5', $run->result, 'the edit waits for a manager to enable it again');
    }

    public function testADraftsConsoleHasNothingGranted(): void
    {
        $service = $this->service();

        $look = $service->run('peek', new RunFunction('look'));
        $secret = $service->run('peek', new RunFunction('secret'));

        self::assertFalse($look->ok);
        self::assertStringContainsString('not granted', (string) json_encode($look->failure));
        self::assertNull($secret->result, 'a draft reads no stored setting');
        self::assertSame([], $service->grantOf($this->package)->hosts);
    }

    public function testADisabledExtensionForgetsItsGrantAndItsConsoleHasNothing(): void
    {
        $this->package->enable(['orders:read'], ['orders:read'], new \DateTimeImmutable('2026-11-10'));
        $this->package->disable(new \DateTimeImmutable('2026-11-10'));

        self::assertSame([], $this->package->granted());
        $look = $this->service()->run('peek', new RunFunction('look'));
        self::assertFalse($look->ok);
        self::assertStringContainsString('not granted', (string) json_encode($look->failure));
    }

    public function testASaveAnswersWithTheFileSavedNotTheWholeExtension(): void
    {
        $service = $this->service();
        $service->writeFile('peek', 'templates/big.html.twig', str_repeat('<p>Long.</p>', 2000));

        $saved = $service->writeFile('peek', 'main.ocs', self::EDITED . "\n");
        $contents = array_column(array_map(static fn($file): array => ['path' => $file->path, 'content' => $file->content], $saved->files ?? []), 'content', 'path');
        self::assertSame(self::EDITED . "\n", $contents['main.ocs']);
        self::assertArrayHasKey('templates/big.html.twig', $contents, 'every file is listed');
        self::assertNull($contents['templates/big.html.twig'], 'without its content: the screen has it');
        self::assertNotNull(array_column(array_map(static fn($file): array => ['path' => $file->path, 'content' => $file->content], $service->package('peek')->files ?? []), 'content', 'path')['templates/big.html.twig'], 'reading it gives them all');
        self::assertSame([null, null], array_map(static fn($file): ?string => $file->content, $service->check('peek')->files ?? []));
    }

    public function testASecretWithoutGoesWithIsPointedOutWhereItIsDeclared(): void
    {
        $service = $this->service();
        $settings = "\nsettings {\n  api_secret: secret { label: \"Secret\" }\n  points: number { label: \"Points\" }\n}\n";
        $findings = $service->writeFile('peek', 'main.ocs', self::APPROVED . $settings)->findings ?? [];
        self::assertCount(1, $findings, implode('; ', $findings));
        self::assertStringStartsWith('main.ocs: The secret "api_secret" names no goes_with', $findings[0]);
        self::assertStringContainsString('(line 5, column 1)', $findings[0]);

        $named = str_replace('label: "Secret" }', 'label: "Secret", goes_with: [] }', $settings);
        self::assertSame([], $service->writeFile('peek', 'main.ocs', self::APPROVED . $named)->findings);
    }

    private function service(): PackageService
    {
        $catalog = HostCatalog::of([
            ...new Builtins()->functions(),
            new HostFunction('shop.orders.find', static fn(Context $c, mixed $id): string => 'order ' . Ops::text($id), 1, 1, '', 'orders:read', HostFunction::QUERY),
            new HostFunction('shop.customers.find', static fn(Context $c, mixed $id): string => 'customer ' . Ops::text($id), 1, 1, '', 'customers:read', HostFunction::QUERY),
        ]);
        $package = $this->package;

        return new PackageService(new class ($package) implements PackageRepository {
            public function __construct(private readonly Package $package) {}

            public function all(): array
            {
                return [$this->package];
            }

            public function enabled(): array
            {
                return $this->package->state()->runs() ? [$this->package] : [];
            }

            public function find(string $slug): ?Package
            {
                return $slug === $this->package->slug() ? $this->package : null;
            }

            public function save(Package $package): void {}

            public function remove(Package $package): void {}
        }, new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore()), new MockClock('2026-11-10 12:00:00'), new NullLogger());
    }
}
