<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Scripting;

use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\Parser;
use OrillaCart\Scripting\Domain\ScriptFailure;
use OrillaCart\Scripting\Domain\SyntaxError;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use PHPUnit\Framework\TestCase;

/** The edges smoothed after the first extensions were written: separators, `fail` as a value, `assert`. */
final class LanguageEdgesTest extends TestCase
{
    public function testTheEndOfALineSeparatesEntriesOfAListOrMapAsACommaDoes(): void
    {
        $script = self::load(<<<'OCS'
            fn f() {
              let map = {
                a: 1
                b: 2,
                c: [
                  1
                  2, 3
                ]
              }
              return [map, { x: 1, y: 2 }, [1, 2]]
            }
            OCS);

        self::assertSame([['a' => 1, 'b' => 2, 'c' => [1, 2, 3]], ['x' => 1, 'y' => 2], [1, 2]], $script->run('fn_f'));
    }

    public function testEntriesOnOneLineStillNeedTheirComma(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Expected ","');
        Parser::parse("fn f() { return [1 2] }\n");
    }

    public function testFailIsAlsoAValue(): void
    {
        $script = self::load(<<<'OCS'
            fn pick(map) { return map.found ?? fail refused("nothing found", { of: map }) }
            fn guarded(x) { return x > 0 ? x : fail error("not positive") }
            OCS);

        self::assertSame(1, $script->run('fn_pick', ['found' => 1]));
        self::assertSame(2, $script->run('fn_guarded', 2));
        try {
            $script->run('fn_pick', []);
            self::fail('fails');
        } catch (ScriptFailure $e) {
            self::assertSame(['refused', 'nothing found', ['of' => []]], [$e->kind, $e->getMessage(), $e->data]);
        }
        try {
            $script->run('fn_guarded', -1);
            self::fail('fails');
        } catch (ScriptFailure $e) {
            self::assertSame(['error', 'not positive'], [$e->kind, $e->getMessage()]);
        }
    }

    public function testAssertFailsWithItsMessageAndLine(): void
    {
        $script = self::load("fn f(x) {\n  assert x == 1, \"x is \" ~ x\n  assert x > 0\n  return 'fine'\n}\nfn g(x) {\n  assert x > 0\n  return 'fine'\n}\n");

        self::assertSame('fine', $script->run('fn_f', 1));
        try {
            $script->run('fn_f', 2);
            self::fail('fails');
        } catch (ScriptFailure $e) {
            self::assertSame(['assertion', 'x is 2', ['line' => 2]], [$e->kind, $e->getMessage(), $e->data]);
        }
        try {
            $script->run('fn_g', 0);
            self::fail('fails');
        } catch (ScriptFailure $e) {
            self::assertSame(['assertion', 'Assertion failed at line 7', ['line' => 7]], [$e->kind, $e->getMessage(), $e->data]);
        }
    }

    private static function load(string $source): \OrillaCart\Scripting\Application\CompiledScript
    {
        $catalog = HostCatalog::of(new Builtins()->functions());

        return new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore())->load($source . "\n", 'edges_' . substr(md5($source), 0, 8));
    }

    /** Design 61: money formats as a number does, and |amount writes it for an API. */
    public function testMoneyIsFormattedAsANumberAndWrittenAsAnAmount(): void
    {
        $price = \OrillaCart\Shared\Domain\Money::of('1450.5');
        self::assertSame('1,450.50', \OrillaCart\Scripting\Domain\Ops::filter('number', $price, [2]));
        self::assertSame('1450.50', \OrillaCart\Scripting\Domain\Ops::filter('amount', $price, []));
        self::assertSame('1450.5000', \OrillaCart\Scripting\Domain\Ops::filter('amount', $price, [4]));
        self::assertSame('3.00', \OrillaCart\Scripting\Domain\Ops::filter('amount', 3, []));
    }

}
