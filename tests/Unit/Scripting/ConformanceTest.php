<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Scripting;

use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\Host\TimeFunctions;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Scripting\Domain\ScriptFailure;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The language as a script sees it: conformance/language.ocs compiled, loaded and run, one function a feature.
 */
final class ConformanceTest extends TestCase
{
    private static ?Scripts $scripts = null;
    private static ?InMemoryScriptStore $store = null;

    /**
     * @return iterable<string, array{string, list<mixed>, mixed}>
     */
    public static function functions(): iterable
    {
        yield 'arithmetic' => ['arithmetic', [], [7, 3.5, 3, 1, -5, 5.0, 5, 7]];
        yield 'money' => ['money_math', ['10', 3], ['30.0000', '28.5000', '9.5000', true, true, '30.0000']];
        yield 'strings' => ['strings', ['Ada'], ['Hello, Ada!', 'HELLO, ADA!', 11, true, true, true, ['x', 'y', 'z'], 'a-b', 'pad', 'Ada', 'ÉLAN', 'Hello', 'Bye, Ada!']];
        yield 'comparisons' => ['comparisons', [], [true, false, true, true, false, true, true, true, true]];
        yield 'logic' => ['logic', [true, false], [false, true, false, true, 'd', 'y', true, null]];
        yield 'control' => ['control', [10], [1, 3, 5, 7, 'a1', 'b2', 10, 20, 30]];
        yield 'maps' => ['maps', [['a' => 1, 'nested' => ['x' => 1]]], [1, 10, 'set', true, null, null, 1, 'two', ['a', 'nested', 'k e y'], 3, 3, [1]]];
        yield 'lists' => ['lists', [], [[1, 2, 3], [2, 1, 3], 3, 2, [1, 2], 2, [6, 2, 4], [3, 2, 1], 1, 9, [1], '312']];
        yield 'tests and filters' => ['tests_and_filters', [''], [true, false, false, true, true, true, true, true, true, true, true, true, 'd', '1.234,50', 2.57, 3]];
        yield 'closures' => ['closures', [10], [11, 21, [11, 12]]];
        yield 'helpers' => ['calls_helpers', [3], 13];
        yield 'a refusal caught' => ['failures', ['refused'], ['refused', 'no way', ['code' => 7]]];
        yield 'a message failed' => ['failures', ['text'], ['error', 'plain message', null]];
        yield 'an error caught' => ['failures', ['error'], ['error', 'Division by zero', null]];
        yield 'a type error caught' => ['failures', ['type'], ['error', '"+" needs a number, not a list', null]];
        yield 'nothing failed' => ['failures', ['none'], 'fine'];
        yield 'json' => ['json_roundtrip', [['a' => [1, 'x', null], 'b' => true]], ['a' => [1, 'x', null], 'b' => true]];
        yield 'times' => ['times', [], ['2026-11-07', '2026-03-03T00:00:00+02:00', '31.01.2026', 7200, true]];
        yield 'conversions' => ['conversions', [], ['12', 'true', '', '1.5000', 2.5, 7, 'n: 1.5true']];
    }

    /**
     * @param list<mixed> $args
     */
    #[DataProvider('functions')]
    public function testAFunctionReturnsWhatTheLanguageSays(string $function, array $args, mixed $expected): void
    {
        self::assertSame($expected, Ops::plain(self::script(['orders:read'])->run('fn_' . $function, ...$args)));
    }

    public function testAFailureNotCaughtReachesTheHostWithItsKind(): void
    {
        $this->expectException(ScriptFailure::class);
        $this->expectExceptionMessage('down');

        self::script()->run('fn_uncaught');
    }

    public function testARunawayLoopIsStoppedByTheStepBudgetAndCannotBeCaught(): void
    {
        $this->expectException(BudgetExceeded::class);
        $this->expectExceptionMessage('more than 100000 steps');

        self::script()->run('fn_runaway');
    }

    public function testAValueThatGrowsPastTheMemoryBudgetStopsTheRun(): void
    {
        $this->expectException(BudgetExceeded::class);
        $this->expectExceptionMessage('bytes');

        self::script()->run('fn_hungry');
    }

    public function testAHostFunctionChecksTheGrantedPermissionsAtRunTimeToo(): void
    {
        self::assertSame([12], self::script(['orders:read'])->run('fn_needs_permission'), 'granted: the stub answers');

        $this->expectException(ScriptError::class);
        $this->expectExceptionMessage('"shop.orders.find" needs the permission "orders:read", which the extension was not granted');
        self::script([])->run('fn_needs_permission');
    }

    public function testTheClassIsCompiledOnceAndKeptByChecksum(): void
    {
        self::script();
        self::script();
        $store = self::$store;
        self::assertNotNull($store);
        self::assertCount(1, $store->kept);
        self::assertStringEndsWith('-' . Scripts::checksum(self::source()), array_key_first($store->kept) ?? '');
    }

    /**
     * @param list<string> $permissions
     */
    private static function script(array $permissions = []): \OrillaCart\Scripting\Application\CompiledScript
    {
        if (self::$scripts === null) {
            $stub = static fn(mixed ...$args): mixed => $args;
            $functions = [...new Builtins()->functions(), ...new TimeFunctions(new MockClock('2026-11-07 10:00:00', 'Europe/Sofia'))->functions()];
            $functions[] = new HostFunction('shop.orders.find', $stub, 1, 1, '', 'orders:read', HostFunction::QUERY);
            $catalog = HostCatalog::of($functions);
            self::$store = new InMemoryScriptStore();
            self::$scripts = new Scripts(new Compiler($catalog), $catalog, self::$store);
        }

        return self::$scripts->load(self::source(), 'conformance', new Budget(bytes: 16 * 1024 * 1024), $permissions);
    }

    private static function source(): string
    {
        return (string) file_get_contents(__DIR__ . '/conformance/language.ocs');
    }
}
