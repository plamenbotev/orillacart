<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Scripting;

use OrillaCart\Scripting\Application\CompiledScript;
use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\GeneratedCodeGate;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Recorder;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\Parser;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Scripting\Domain\ScriptFailure;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use PHPUnit\Framework\TestCase;

/**
 * Design 71: every run knows where it is and what called it; the debug build records the lines with their
 * variables, the functions entered and left, the host's calls and what debug() marked, bounded and with secrets
 * hidden, and changes nothing the script does.
 */
final class DebuggerTest extends TestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "dbg" { name: "Debug", needs: { http: ["api.example"] } }

        fn price_for(qty) {
          let unit = 2
          let total = unit * qty
          if total > 10 { fail error("too many: " ~ total) }
          return total
        }

        fn order(qty) {
          let price = price_for(qty)
          return price + 1
        }

        fn calls() {
          let answer = http.get("https://api.example/x?key=s3cr3t-value")
          debug(answer.status)
          return answer.status
        }

        fn recovers() {
          try { price_for(9) } catch e { let caught = e.message }
          return [1, 2]|map(fn(n) { return n * 10 })
        }

        fn long() {
          let text = ""
          let count = 0
          while count < 12000 { count = count + 1 }
          return count
        }
        OCS;

    private Scripts $scripts;

    protected function setUp(): void
    {
        $catalog = HostCatalog::of([
            ...new Builtins()->functions(),
            new HostFunction('http.get', static fn(Context $c, mixed $url): array => ['status' => 200, 'headers' => ['Authorization' => 'Bearer x'], 'body' => ''], 1, 2, '', 'http', HostFunction::HTTP),
        ]);
        $this->scripts = new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore());
    }

    public function testAnErrorSaysWhereItHappenedAndWhatCalledIt(): void
    {
        $script = $this->load();
        try {
            $script->run('fn_order', 9);
            self::fail('no error');
        } catch (ScriptFailure $e) {
            self::assertSame('too many: 18', $e->getMessage());
        }

        self::assertSame([['fn' => 'price_for', 'line' => 6], ['fn' => 'order', 'line' => 11]], self::context($script)->stack(), 'innermost first, each at its line');
    }

    public function testAnErrorTheScriptCaughtIsNotWhereItFailed(): void
    {
        $script = $this->load();
        self::assertSame([10, 20], $script->run('fn_recovers'));
        self::assertSame([], self::context($script)->stack());
    }

    public function testTheDebugBuildRecordsLinesVariablesCallsAndReturns(): void
    {
        $recorder = new Recorder(['s3cr3t-value']);
        $script = $this->load($recorder);
        self::assertSame(9, $script->run('fn_order', 4), 'recording changes nothing it does');

        $events = self::plain($recorder->events());
        self::assertSame(['k' => 'enter', 'fn' => 'order', 'line' => 10, 'depth' => 1], $events[0]);
        self::assertSame(['k' => 'line', 'line' => 11, 'depth' => 1, 'vars' => ['qty' => 4]], $events[1], 'its arguments before the first line');
        $lines = array_values(array_filter($events, static fn(array $e): bool => $e['k'] === 'line'));
        self::assertSame([11, 4, 5, 6, 7, 12], array_column($lines, 'line'));
        self::assertSame(['qty' => 4, 'unit' => 2, 'total' => 8], $lines[4]['vars'], 'the variables at the return');
        self::assertSame(2, $lines[2]['depth'], 'inside price_for');
        $returns = array_values(array_filter($events, static fn(array $e): bool => $e['k'] === 'return'));
        self::assertSame([8, 9], array_column($returns, 'value'));
        self::assertNotSame($this->load()::class, $script::class, 'a class of its own, never the one the shop runs');
    }

    public function testCallsAreRecordedWithTheirAnswersAndSecretsNeverShow(): void
    {
        $recorder = new Recorder(['s3cr3t-value']);
        $this->load($recorder)->run('fn_calls');

        $call = array_values(array_filter(self::plain($recorder->events()), static fn(array $e): bool => $e['k'] === 'call'))[0];
        self::assertSame('http.get', $call['name']);
        self::assertSame([Recorder::REDACTED], $call['args'], 'a secret setting\'s value, wherever it is in a text');
        $result = $call['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['headers']);
        self::assertSame(Recorder::REDACTED, $result['headers']['Authorization'] ?? null, 'a key that names a secret');
        self::assertSame(16, $call['line']);
        $marked = array_values(array_filter(self::plain($recorder->events()), static fn(array $e): bool => $e['k'] === 'debug'));
        self::assertSame([['k' => 'debug', 'value' => 200, 'line' => 17, 'depth' => 1]], $marked);
    }

    public function testARecordingIsBoundedAndTheRunGoesOn(): void
    {
        $recorder = new Recorder();
        self::assertSame(12000, $this->load($recorder)->run('fn_long'));
        self::assertTrue($recorder->cut());
        self::assertCount(Recorder::MAX_EVENTS, $recorder->events());

        $shaped = $recorder->shape(['text' => str_repeat('a', 500), 'list' => range(1, 80)], 0);
        self::assertSame(['$text' => str_repeat('a', Recorder::MAX_TEXT), 'length' => 500], ((array) $shaped)['text']);
        $list = ((array) $shaped)['list'];
        self::assertIsArray($list);
        self::assertSame(80, $list['count']);
    }

    public function testBothBuildsPassTheGate(): void
    {
        $program = Parser::parse(self::SCRIPT);
        $compiler = $this->compilerOf();
        foreach ([false, true] as $debug) {
            $compiled = $compiler->compile($program, 'dbg_' . (int) $debug, 'OrillaCart\Generated\Scripts\Site1', $debug);
            self::assertNotNull($compiled->source, implode("\n", array_map(strval(...), $compiled->findings)));
            self::assertNull(GeneratedCodeGate::refuse($compiled->source, '1'), $debug ? 'the debug build' : 'the normal build');
        }
        self::assertStringNotContainsString("'unit' => \$v_unit", (string) $compiler->compile($program, 'dbg_n', 'OrillaCart\Generated\Scripts\Site1')->source, 'the normal build passes no variables');
    }

    public function testDebugAnswersItsValueAndRecordsNothingWithoutARecording(): void
    {
        $script = $this->load();
        self::assertSame(200, $script->run('fn_calls'));
    }

    public function testAnArityErrorIsStillTheScripts(): void
    {
        $this->expectException(ScriptError::class);
        $this->load()->run('fn_order');
    }

    private function load(?Recorder $recorder = null): CompiledScript
    {
        return $this->scripts->load(self::SCRIPT, 'dbg', grant: new Grant('dbg', ['http'], ['api.example']), recorder: $recorder);
    }

    private function compilerOf(): Compiler
    {
        return new Compiler(HostCatalog::of([...new Builtins()->functions(), new HostFunction('http.get', static fn(): null => null, 1, 2, '', 'http')]));
    }

    /**
     * The events as JSON reads them: a recorded map is an object.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private static function plain(array $events): array
    {
        $plain = json_decode((string) json_encode($events), true);
        self::assertIsArray($plain);

        /** @var list<array<string, mixed>> */
        return $plain;
    }

    private static function context(CompiledScript $script): Context
    {
        $context = new \ReflectionProperty(CompiledScript::class, 'c')->getValue($script);
        self::assertInstanceOf(Context::class, $context);

        return $context;
    }
}
