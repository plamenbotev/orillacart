<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Scripting;

use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\GeneratedCodeGate;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\Parser;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The gate on generated code: what the compiler makes passes, anything outside its grammar is refused, and
 * hostile scripts (every byte in strings, every shape of name) compile to code the gate still passes and that
 * gives the string back unchanged.
 */
final class GeneratedCodeGateTest extends TestCase
{
    private const string HEAD = "<?php\n\ndeclare(strict_types=1);\n\nnamespace OrillaCart\\Generated\\Scripts\\Site1;\n\n/** Generated from a script package: do not edit. */\nfinal class Script_t extends \\OrillaCart\\Scripting\\Application\\CompiledScript\n{\n    public static function manifest(): array\n    {\n        return [];\n    }\n\n    public function fn_f()\n    {\n        \$this->c->step();\n";
    private const string TAIL = "        return null;\n    }\n}\n";

    public function testTheConformanceScriptPasses(): void
    {
        $compiled = self::compile((string) file_get_contents(__DIR__ . '/conformance/language.ocs'));

        self::assertSame([], $compiled->findings);
        self::assertNotNull($compiled->source);
        self::assertNull(GeneratedCodeGate::refuse($compiled->source));
        self::assertNull(GeneratedCodeGate::refuse(self::HEAD . self::TAIL));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function refused(): iterable
    {
        yield 'a PHP function' => ['        return strlen($v_x);', 'the word strlen'];
        yield 'a namespaced function' => ['        return \\strlen($v_x);', 'the class \\strlen'];
        yield 'another class' => ['        return \\OrillaCart\\Platform\\Kernel::boot();', 'the class \\OrillaCart\\Platform\\Kernel'];
        yield 'new' => ['        return new \\OrillaCart\\Scripting\\Domain\\Ops();', 'the token T_NEW'];
        yield 'eval' => ["        return eval('1');", 'the token T_EVAL'];
        yield 'include' => ["        return include 'x.php';", 'the token T_INCLUDE'];
        yield 'a backtick' => ['        return `ls`;', 'the token'];
        yield 'a plain double-quoted string' => ['        return "x";', 'a double-quoted string'];
        yield 'an interpolated string' => ['        return "{$v_x}";', 'the token " (")'];
        yield 'a heredoc' => ["        return <<<X\nhi\nX;", 'the token T_START_HEREDOC'];
        yield 'a variable variable' => ['        return $$v_x;', 'the token'];
        yield 'another variable' => ['        return $_GET;', 'the variable $_GET'];
        yield 'globals' => ["        return \$GLOBALS['wpdb'];", 'the variable $GLOBALS'];
        yield 'a method on a value' => ['        return $v_x->add(1);', 'a method call on a value'];
        yield 'a method on the context' => ['        return $this->c->catalog();', 'the member catalog'];
        yield 'a property of the script' => ['        return $this->catalog;', 'the member catalog'];
        yield 'an Ops method that is not one' => ['        return \\OrillaCart\\Scripting\\Domain\\Ops::exec($v_x);', 'Ops::exec'];
        yield 'Ops as a value' => ['        return \\OrillaCart\\Scripting\\Domain\\Ops::class;', 'out of place'];
        yield 'a static call on the script' => ['        return static::manifest();', 'a static call not on Ops'];
        yield 'CompiledScript as a value' => ['        return \\OrillaCart\\Scripting\\Application\\CompiledScript::manifest();', 'out of place'];
        yield 'a constant' => ['        return PHP_VERSION;', 'the word PHP_VERSION'];
        yield 'a closing tag' => ["        ?>\n<?php", 'the token T_CLOSE_TAG'];
        yield 'inline HTML' => ["        ?>hi<?php\n", 'the token T_CLOSE_TAG'];
        yield 'a line comment' => ['        // hidden', 'the token T_COMMENT'];
        yield 'goto' => ['        goto a;', 'the token T_GOTO'];
        yield 'a call of a variable' => ["        return \$v_f('id');", 'a call of a value after $v_f'];
        yield 'a call of a string' => ["        return 'passthru'('id');", "a call of a value after 'passthru'"];
        yield 'a call of a list' => ["        return ['a', 'b']();", 'a call of a value after ]'];
        yield 'a call of a call' => ["        return \\OrillaCart\\Scripting\\Domain\\Ops::get(\$v_x, 'f')('id');", 'a call of a value after )'];
        yield 'a call of a closure made in place' => ['        return (function () { return null; })();', 'a call of a value after )'];
        yield 'a call of a member' => ["        return \$this->c->call('x', [])('id');", 'a call of a value after )'];
        yield 'a call of a word' => ["        return null('id');", 'a call of a value after null'];
        yield 'a callable string' => ["        return \\OrillaCart\\Scripting\\Domain\\Ops::invoke('system', ['ls']);", null];
    }

    #[DataProvider('refused')]
    public function testCodeOutsideTheGrammarIsRefused(string $line, ?string $reason): void
    {
        $refusal = GeneratedCodeGate::refuse(self::HEAD . $line . "\n" . self::TAIL);
        if ($reason === null) {
            // A string stays a string: Ops::invoke refuses what is not the script's own closure at run time.
            self::assertNull($refusal);

            return;
        }
        self::assertNotNull($refusal);
        self::assertStringContainsString($reason, $refusal);
    }

    public function testTheWholeShapeIsChecked(): void
    {
        self::assertSame('the source does not start with the PHP open tag', GeneratedCodeGate::refuse("<?php declare(strict_types=1);\n"));
        self::assertStringContainsString('a comment the compiler does not write', (string) GeneratedCodeGate::refuse(str_replace('do not edit', 'edited', self::HEAD . self::TAIL)));
        self::assertStringContainsString('the name Other\\Place', (string) GeneratedCodeGate::refuse(str_replace('OrillaCart\\Generated\\Scripts\\Site1', 'Other\\Place', self::HEAD . self::TAIL)));
        self::assertStringContainsString('the name OrillaCart\\Generated\\Scripts', (string) GeneratedCodeGate::refuse(str_replace('Scripts\\Site1', 'Scripts', self::HEAD . self::TAIL)), 'no site: the namespace every site shared before');
        self::assertNull(GeneratedCodeGate::refuse(self::HEAD . self::TAIL, '1'), 'this site\'s class');
        self::assertStringContainsString('the namespace of site 1, not site 3', (string) GeneratedCodeGate::refuse(self::HEAD . self::TAIL, '3'), 'another site\'s class, however well signed');
        self::assertStringContainsString('the class name Evil', (string) GeneratedCodeGate::refuse(str_replace('Script_t', 'Evil', self::HEAD . self::TAIL)));
        self::assertStringContainsString('the method name run', (string) GeneratedCodeGate::refuse(str_replace('fn_f', 'run', self::HEAD . self::TAIL)));
        self::assertStringContainsString('2 classes', (string) GeneratedCodeGate::refuse(self::HEAD . self::TAIL . "final class Script_u extends \\OrillaCart\\Scripting\\Application\\CompiledScript { public static function manifest(): array { return []; } }\n"));
    }

    /** Every byte but the newline, in every place a script string reaches the generated code, comes back as it went. */
    public function testHostileStringsCompileToCodeTheGatePassesAndRoundTrip(): void
    {
        $samples = ["'", '\\', "\\'", "'; system('id'); //", '${x}', '{$x}', '$this->c', '?>', '<?php', '*/', '/**', "\0", "\r", "\t", '"', '`', 'é漢字🙂', '\\u0027', "\\\\'"];
        mt_srand(7);
        for ($i = 0; $i < 40; ++$i) {
            $bytes = '';
            for ($j = 0, $n = mt_rand(0, 24); $j < $n; ++$j) {
                $bytes .= \chr(mt_rand(0, 255));
            }
            $samples[] = str_replace("\n", '', $bytes);
        }
        $store = new InMemoryScriptStore();
        $scripts = new Scripts(new Compiler(HostCatalog::of(new Builtins()->functions())), HostCatalog::of(new Builtins()->functions()), $store);
        foreach ($samples as $k => $sample) {
            $quoted = self::quote($sample);
            // The string as a value, a map key, a declaration's text and an index all reach the PHP source.
            $source = "extension \"ext_{$k}\" {\n  name: {$quoted}\n}\nfn f(m) {\n  let out = {$quoted}\n  let map = { {$quoted}: 1 }\n  return [out, map[{$quoted}], m[{$quoted}]]\n}\n";
            $compiled = $scripts->compile($source, 'fuzz_' . $k);
            self::assertSame([], array_map(strval(...), $compiled->findings), 'sample ' . $k . ' compiles: ' . bin2hex($sample));
            self::assertNotNull($compiled->source);
            self::assertNull(GeneratedCodeGate::refuse($compiled->source), 'sample ' . $k . ' passes the gate: ' . bin2hex($sample));
            $script = $scripts->load($source, 'fuzz_' . $k);
            self::assertSame([$sample, 1, 'member'], $script->run('fn_f', [$sample => 'member']), 'sample ' . $k . ' round-trips: ' . bin2hex($sample));
            self::assertSame($sample, $script::manifest()[0]['props']['name'] ?? null);
        }
    }

    /** A script's double-quoted string holding the bytes: the lexer knows `\"`, `\\`, `\n`, `\r`, `\t`. */
    private static function quote(string $bytes): string
    {
        return '"' . strtr($bytes, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) . '"';
    }

    private static function compile(string $source): \OrillaCart\Scripting\Application\Dto\Compiled
    {
        $stub = static fn(mixed ...$args): mixed => $args;
        $functions = [...new Builtins()->functions(), ...new \OrillaCart\Scripting\Application\Host\TimeFunctions(new \Symfony\Component\Clock\MockClock())->functions()];
        $functions[] = new HostFunction('http.post', $stub, 2, 2, '', 'http', HostFunction::HTTP);
        $functions[] = new HostFunction('shop.orders.find', $stub, 1, 1, '', 'orders:read', HostFunction::QUERY);
        $functions[] = new HostFunction('objects.*.query', $stub, 1, 1, '');
        $functions[] = new HostFunction('log.info', $stub, 1, 2, '');

        return new Compiler(HostCatalog::of($functions))->compile(Parser::parse($source . "\n"), 'gate', Compiler::NAMESPACE . '\\Site1');
    }
}
