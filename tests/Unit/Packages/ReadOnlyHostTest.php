<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Packages\Application\ScriptRunner;
use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\Host\HostNamespace;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Application\ScriptTests;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use PHPUnit\Framework\TestCase;

/**
 * The host of a request that may only read: every function that writes or reaches outside the shop refuses, an
 * HTTP GET among them, and an extension's own tests run against the same narrowed host, their recording stand-ins
 * for HTTP and jobs aside.
 */
final class ReadOnlyHostTest extends TestCase
{
    public function testWhatCountsAsAWriteIsWhatTheFunctionDeclares(): void
    {
        $noop = static fn(): null => null;

        self::assertTrue(ScriptRunner::writes(new HostFunction('shop.orders.mark', $noop, 2, 2, '', 'orders:read', writes: true)), 'declared, whatever its permission says');
        self::assertFalse(ScriptRunner::writes(new HostFunction('shop.orders.find', $noop, 1, 1, '', 'orders:write', writes: false)));
        self::assertTrue(ScriptRunner::writes(new HostFunction('shop.orders.look', $noop, 1, 1, '', 'orders:read')), 'one that says nothing is taken to write');
    }

    public function testEveryFunctionOfTheShopsHostSaysWhetherItWrites(): void
    {
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.php') && str_contains((string) file_get_contents($file->getPathname()), 'implements HostNamespace')) {
                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/^namespace ([^;]+);/m', $source, $namespace) === 1 && preg_match('/^final (?:readonly )?class (\w+)/m', $source, $class) === 1) {
                    $classes[] = $namespace[1] . '\\' . $class[1];
                }
            }
        }
        self::assertGreaterThan(8, \count($classes));
        $writing = [];
        foreach ($classes as $class) {
            \assert(class_exists($class));
            $host = new \ReflectionClass($class)->newInstanceWithoutConstructor();
            \assert($host instanceof HostNamespace);
            foreach ($host->functions() as $function) {
                self::assertNotNull($function->writes, \sprintf('%s says whether it writes', $function->name));
                if ($function->writes) {
                    $writing[] = $function->name;
                }
            }
        }
        sort($writing);

        self::assertSame(['fields.set', 'http.delete', 'http.get', 'http.patch', 'http.post', 'http.put', 'jobs.push', 'objects.*.create', 'objects.*.delete', 'objects.*.update', 'shop.attributes.save_set', 'shop.categories.ensure', 'shop.email.send', 'shop.orders.create', 'shop.orders.mark', 'shop.orders.unmark', 'shop.products.add_variation', 'shop.products.change', 'shop.products.change_variation', 'shop.products.create', 'shop.products.mark', 'shop.products.publish', 'shop.products.trash', 'shop.products.unmark', 'shop.products.unpublish'], $writing);
    }

    public function testTheReadingHostRefusesWritesAndKeepsWhatItIsTold(): void
    {
        $catalog = HostCatalog::of([
            new HostFunction('http.get', static fn(): string => 'real', 1, 2, '', 'http', HostFunction::HTTP, writes: true),
            new HostFunction('jobs.push', static fn(): string => 'real', 1, 3, '', writes: true),
            new HostFunction('shop.orders.find', static fn(): string => 'real', 1, 1, '', 'orders:read', writes: false),
        ]);

        $reading = ScriptRunner::readingOnly($catalog, ['jobs.push']);

        self::assertSame($catalog->all()['shop.orders.find'], $reading->all()['shop.orders.find'], 'a read stays');
        self::assertSame($catalog->all()['jobs.push'], $reading->all()['jobs.push'], 'a kept function stays');
        self::assertNotSame($catalog->all()['http.get'], $reading->all()['http.get'], 'a GET refuses');
    }

    public function testAnExtensionsTestsOnAReadOnlyRequestCannotWrite(): void
    {
        $marked = [];
        $placed = [];
        $catalog = HostCatalog::of([
            ...new Builtins()->functions(),
            new HostFunction('shop.orders.mark', static function (Context $c, mixed $id) use (&$marked): bool {
                $marked[] = $id;

                return true;
            }, 1, 1, '', 'orders:read', writes: true),
            new HostFunction('shop.orders.create', static fn(Context $c): int => 1, 0, 0, '', 'orders:write', HostFunction::CALL, static function (Context $c) use (&$placed): int {
                $placed[] = 'test order';

                return 7;
            }, writes: true),
            new HostFunction('http.get', static fn(Context $c): array => ['status' => 500, 'headers' => [], 'body' => 'real', 'json' => null], 1, 2, '', 'http', HostFunction::HTTP, writes: true),
        ]);
        $scripts = new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore());
        $source = <<<'OCS'
            extension "marker" { name: "Marker", needs: { http: ["api.marker.test"], orders: [read, write] } }

            test "marks" fn() {
              shop.orders.mark(5)
            }

            test "places an order" fn() {
              shop.orders.create()
            }

            test "asks the map" {
              http: { "GET https://api.marker.test/x": { status: 200, json: { ok: true } } }
            } fn() {
              assert http.get("https://api.marker.test/x").status == 200
            }
            OCS;
        $narrow = static fn(HostCatalog $host): HostCatalog => ScriptRunner::readingOnly($host, ScriptTests::RECORDED);

        $results = new ScriptTests($scripts)->run($source . "\n", 'marker_tests', new Grant('marker', ['http', 'orders:read', 'orders:write'], ['api.marker.test']), null, $narrow);

        self::assertSame([], $marked, 'no order was marked');
        self::assertSame([], $placed, 'no test order was placed');
        self::assertFalse($results[0]->ok);
        self::assertStringContainsString('may only read', (string) $results[0]->message);
        self::assertFalse($results[1]->ok);
        self::assertStringContainsString('may only read', (string) $results[1]->message);
        self::assertTrue($results[2]->ok, (string) $results[2]->message);
    }
}
