<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Scripting;

use OrillaCart\Scripting\Application\CompiledScript;
use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A filter that would make more than the run has left stops it before the memory is spent. */
final class FilterBudgetTest extends TestCase
{
    public function testAListHoldingItselfTwiceOverIsRefusedBeforeItIsCopiedOut(): void
    {
        $script = self::load("fn f() { let a = [1]; for i in 1..40 { a = [a, a] }\n return a|json }");

        $this->expectException(BudgetExceeded::class);
        $script->run('fn_f');
    }

    public function testTheHostsJsonTextCountsTheSameWay(): void
    {
        $script = self::load("fn f() { let a = [1]; for i in 1..40 { a = [a, a] }\n return json.text(a) }");

        $this->expectException(BudgetExceeded::class);
        $script->run('fn_f');
    }

    public function testAValueNestedTooDeepIsRefused(): void
    {
        $script = self::load("fn f() { let a = 1; for i in 1..100 { a = [a] }\n return a|json }");

        $this->expectException(BudgetExceeded::class);
        $this->expectExceptionMessage('deeper than 64 levels');
        $script->run('fn_f');
    }

    public function testNumbersSeparatorsArePredictedAgainstTheBytesLeft(): void
    {
        self::assertSame('1 234 567,50', Ops::filter('number', 1234567.5, [2, ',', ' ']));
        self::assertSame('1,000', Ops::filter('number', 1000, [], 5));

        $this->expectException(BudgetExceeded::class);
        Ops::filter('number', 1e300, [0, '.', str_repeat('x', 100000)], 1000000);
    }

    public function testSplitIsPredictedAgainstTheBytesLeft(): void
    {
        $script = self::load('fn f(text) { return text|split("") }', new Budget(bytes: 200000));
        $characters = $script->run('fn_f', str_repeat('x', 1000));
        self::assertIsArray($characters);
        self::assertCount(1000, $characters);

        $script = self::load('fn f(text) { return text|split("") }', new Budget(bytes: 200000));
        $this->expectException(BudgetExceeded::class);
        $script->run('fn_f', str_repeat('x', 100000));
    }

    /** @return iterable<string, array{string, mixed, list<mixed>}> */
    public static function growing(): iterable
    {
        yield 'join with a long separator' => ['join', range(1, 1000), [str_repeat('-', 10000)]];
        yield 'split by a character' => ['split', str_repeat('a,', 100000), [',']];
        yield 'reverse a long text' => ['reverse', str_repeat('ab', 100000), []];
        yield 'replace that grows the text' => ['replace', str_repeat('a', 10000), [['a' => str_repeat('b', 1000)]]];
        yield 'json of a large list' => ['json', array_fill(0, 100000, 'x'), []];
    }

    /** @param list<mixed> $args */
    #[DataProvider('growing')]
    public function testAFilterThatWouldGrowPastTheRoomIsStopped(string $filter, mixed $value, array $args): void
    {
        $this->expectException(BudgetExceeded::class);
        Ops::filter($filter, $value, $args, 100000);
    }

    public function testFiltersWithinTheRoomStillWork(): void
    {
        self::assertSame('1-2-3', Ops::filter('join', [1, 2, 3], ['-'], 100));
        self::assertSame(['a', 'b'], Ops::filter('split', 'a,b', [','], 100));
        self::assertSame('cba', Ops::filter('reverse', 'abc', [], 100));
        self::assertSame('xbbx', Ops::filter('replace', 'xax', [['a' => 'bb']], 100));
        self::assertSame('[1,"2.0000"]', Ops::filter('json', [1, Money::of('2')], [], 100));
    }

    /** @return iterable<string, array{string, mixed, int}> */
    public static function decimals(): iterable
    {
        yield 'number, huge' => ['number', 1, 2000000000];
        yield 'number, negative' => ['number', 1, -1];
        yield 'amount, huge' => ['amount', 1, 21];
        yield 'round, huge' => ['round', 1.5, 1000];
        yield 'round, far left' => ['round', 1.5, -21];
        yield 'round of money past its four decimals' => ['round', Money::of('1'), 5];
    }

    #[DataProvider('decimals')]
    public function testDecimalsAreBounded(string $filter, mixed $value, int $decimals): void
    {
        $this->expectException(ScriptError::class);
        $this->expectExceptionMessage('decimals');
        Ops::filter($filter, $value, [$decimals]);
    }

    public function testDecimalsWithinTheBoundsWork(): void
    {
        self::assertSame('1.00000000000000000000', Ops::filter('number', 1, [20]));
        self::assertSame(1200.0, Ops::filter('round', 1234.5, [-2]));
        $rounded = Ops::filter('round', Money::of('1.2346'), [2]);
        self::assertInstanceOf(Money::class, $rounded);
        self::assertSame('1.2300', $rounded->toFixed());
    }

    private static function load(string $source, Budget $budget = new Budget()): CompiledScript
    {
        $catalog = HostCatalog::of(new Builtins()->functions());

        return new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore())->load($source . "\n", 'budget_' . substr(md5($source), 0, 8), $budget);
    }
}
