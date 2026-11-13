<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Packages\Application\AnonymousCaller;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Packages\Domain\PackageState;
use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use OrillaCart\Tests\Fixtures\Shared\CountingRateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Design 74: an anonymous caller's failures count once an hour each towards stopping an extension, so no visitor
 * switches one off for everyone by sending what it breaks on ten times; staff, jobs and the command line count in
 * full. And a route's `cache` and `limit` are pointed out where they do nothing.
 */
final class RouteLimitsAndFailuresTest extends TestCase
{
    private Package $package;
    private ?string $caller = null;

    protected function setUp(): void
    {
        $at = new \DateTimeImmutable('2026-11-13');
        $this->package = new Package('shopx', 'Shop X', $at);
        $this->package->write('main.ocs', "extension \"shopx\" { name: \"Shop X\" }\n", $at);
        $this->package->enable([], [], $at);
    }

    public function testOneVisitorsFailuresCountOnceAnHour(): void
    {
        $service = $this->service();
        $this->caller = 'ip:203.0.113.7';
        for ($i = 0; $i < Package::FAILURES_TO_STOP * 2; ++$i) {
            $service->failed('shopx', 'Division by zero');
        }
        self::assertSame(1, $this->package->failures(), 'the same visitor again is logged, not counted');

        for ($i = 1; $i < Package::FAILURES_TO_STOP; ++$i) {
            $this->caller = 'ip:203.0.113.' . (10 + $i);
            $service->failed('shopx', 'Division by zero');
        }
        self::assertSame(PackageState::Stopped, $this->package->state(), 'ten visitors in a row meet a real failure');
    }

    public function testStaffJobsAndTheCommandLineCountInFull(): void
    {
        $service = $this->service();
        $this->caller = null;
        for ($i = 0; $i < Package::FAILURES_TO_STOP; ++$i) {
            $service->failed('shopx', 'Division by zero');
        }
        self::assertSame(PackageState::Stopped, $this->package->state());
    }

    public function testARoutesCacheAndLimitArePointedOutWhereTheyDoNothing(): void
    {
        $script = <<<'OCS'
            extension "shopx" { name: "Shop X" }
            route GET "/list" { who: public, cache: 300 } fn(request) { return {} }
            route POST "/code" { who: public, limit: 20 } fn(request) { return {} }
            route POST "/hook" { who: public, limit: none } fn(request) { return {} }
            route POST "/save" { who: manager, cache: 60 } fn(request) { return {} }
            route GET "/read" { who: public, limit: 5 } fn(request) { return {} }
            route GET "/long" { who: public, cache: 99999 } fn(request) { return {} }
            OCS;
        $findings = $this->service()->writeFile('shopx', 'main.ocs', $script)->findings ?? [];

        self::assertCount(3, $findings, implode("\n", $findings));
        self::assertStringContainsString('cache: seconds (1 to 3600) keeps a visitor\'s answer from a public GET route; POST /save', $findings[0]);
        self::assertStringContainsString('(line 5, column 1)', $findings[0]);
        self::assertStringContainsString('limit: n (1 to 100000 in 10 minutes) or limit: none counts each caller of a public route that changes something; GET /read', $findings[1]);
        self::assertStringContainsString('GET /long', $findings[2]);
    }

    private function service(): PackageService
    {
        $catalog = HostCatalog::of(new Builtins()->functions());
        $limits = new CountingRateLimiter();
        $limits->limits[PackageService::FAILURES_GUARD] = 1;
        $caller = new class ($this) implements AnonymousCaller {
            public function __construct(private readonly RouteLimitsAndFailuresTest $test) {}

            public function who(): ?string
            {
                return $this->test->caller();
            }
        };

        return new PackageService(new class ($this->package) implements PackageRepository {
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
        }, new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore()), new MockClock('2026-11-13 12:00:00'), new NullLogger(), callers: $caller, limits: $limits);
    }

    public function caller(): ?string
    {
        return $this->caller;
    }
}
