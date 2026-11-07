<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Scripting;

use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\Host\TimeFunctions;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Domain\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The static check: what the compiler refuses, and what it guarantees about the PHP it makes.
 */
final class CompilerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'an unknown name' => ['fn f() { return x }', 'Unknown name "x" (line 1, column 17)'];
        yield 'an unknown function' => ['fn f() { return nothing() }', 'Unknown name "nothing"'];
        yield 'an unknown function of a known namespace' => ['fn f() { return http.fetch("x") }', 'Unknown function "http.fetch"'];
        yield 'a namespace as a value' => ['fn f() { return http }', '"http" is a namespace: call one of its functions'];
        yield 'a function not called' => ['fn f() { return http.post }', '"http.post" is a function or a namespace: call it'];
        yield 'too few arguments' => ['fn f() { return http.post("x") }', '"http.post" takes 2 arguments, not 1'];
        yield 'too many arguments' => ['fn f() { return money(1, "EUR", 3) }', '"money" takes 1 to 2 arguments, not 3'];
        yield 'a permission not declared' => ['fn f() { return shop.orders.find(1) }', '"shop.orders.find" needs the permission "orders:read": declare it in the extension\'s needs'];
        yield 'a method on a value' => ['fn f(a) { return a.b() }', 'Values have no methods: use a filter, or a function of the host'];
        yield 'an unknown filter' => ['fn f(a) { return a|shout }', 'Unknown filter "shout"'];
        yield 'an unknown test' => ['fn f(a) { return a is loud }', 'Unknown test "loud"'];
        yield 'break outside a loop' => ['fn f() { break }', '"break" belongs in a loop'];
        yield 'assigning an undeclared variable' => ['fn f() { x = 1 }', '"x" is not declared: use "let x = …" first'];
        yield 'code in a declaration\'s function' => ['route GET "/x" { check: fn() { return a } }', 'Unknown name "a"'];
        yield 'a syntax error' => ['fn f( { }', 'Expected a name, not "{"'];
    }

    #[DataProvider('refused')]
    public function testTheStaticCheckRefuses(string $source, string $finding): void
    {
        $compiled = self::compile($source);

        self::assertNull($compiled->source);
        self::assertStringContainsString($finding, implode("\n", array_map(strval(...), $compiled->findings)));
    }

    public function testAPermissionDeclaredIsAccepted(): void
    {
        $compiled = self::compile("extension \"x\" { needs: { orders: [read, write], http: [\"a.example\"] } }\nfn f() { return shop.orders.find(1) }");

        self::assertSame([], $compiled->findings);
        self::assertNotNull($compiled->source);
        self::assertStringContainsString("\$this->c->call('shop.orders.find', [1])", $compiled->source);
    }

    public function testTheManifestHoldsTheDeclarationsAsData(): void
    {
        $compiled = self::compile(<<<'OCS'
            settings { key: secret { label: "Key", required: true }, mode: choice { of: ["a", "b"] }, count: -2 }
            route GET "/tickets" { who: account, check: fn(v) { return v } } fn(request) { return 1 }
            payment_gateway "pay" { fn start(s, t) { return null } settings { k: text } }
            OCS);

        self::assertSame([], $compiled->findings);
        self::assertNotNull($compiled->source);
        eval(substr($compiled->source, 5));
        $manifest = $compiled->className::manifest();
        $at = static function (array $path) use ($manifest): mixed {
            $value = $manifest;
            foreach ($path as $key) {
                if (!\is_int($key) && !\is_string($key)) {
                    return null;
                }
                $value = \is_array($value) ? ($value[$key] ?? null) : null;
            }

            return $value;
        };
        self::assertSame('settings', $at([0, 'kind']));
        self::assertSame(['$spec' => 'secret', 'label' => 'Key', 'required' => true], $at([0, 'props', 'key']));
        self::assertSame(['$spec' => 'choice', 'of' => ['a', 'b']], $at([0, 'props', 'mode']));
        self::assertSame(-2, $at([0, 'props', 'count']));
        self::assertSame(['GET'], $at([1, 'words']));
        self::assertSame('/tickets', $at([1, 'name']));
        self::assertSame(['$spec' => 'account'], $at([1, 'props', 'who']));
        $check = $at([1, 'props', 'check']);
        self::assertIsString($check);
        self::assertMatchesRegularExpression('/^m_\d+$/', $check, 'a function in a declaration is its method');
        $handler = $at([1, 'handler']);
        self::assertIsString($handler);
        self::assertMatchesRegularExpression('/^m_\d+$/', $handler);
        self::assertSame(['start'], array_keys((array) $at([2, 'functions'])));
        self::assertSame('settings', $at([2, 'nested', 0, 'kind']));
    }

    public function testTheGeneratedCodeNamesNothingOfPhpButTheRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/conformance/language.ocs');
        $compiled = self::compile($source);

        self::assertSame([], $compiled->findings);
        self::assertNotNull($compiled->source);
        $code = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'/', "''", $compiled->source) ?? '';
        $code = preg_replace('/^namespace .*$/m', '', $code) ?? '';
        // No class but the runtime's, no function call but through Ops, $this->c and the script's own methods.
        preg_match_all('/\\\\[A-Za-z_\\\\]+/', $code, $classes);
        $named = array_values(array_unique(array_filter($classes[0], static fn(string $name): bool => $name !== '\\')));
        sort($named);
        self::assertSame(['\\OrillaCart\\Scripting\\Application\\CompiledScript', '\\OrillaCart\\Scripting\\Domain\\Catchable', '\\OrillaCart\\Scripting\\Domain\\Ops'], $named);
        // Calls through Ops, the context and the class's own methods taken out, nothing else may be called.
        $bare = preg_replace('/(::|->|function )\w+\s*\(/', '#', $code) ?? '';
        preg_match_all('/\b([a-z_][a-z0-9_]*)\s*\(/i', $bare, $calls);
        $allowed = ['declare', 'if', 'elseif', 'while', 'foreach', 'catch', 'function', 'use'];
        self::assertSame([], array_values(array_diff(array_unique($calls[1]), $allowed)), 'no bare PHP function is called');
        self::assertDoesNotMatchRegularExpression('/\bnew\b|\beval\b|\binclude\b|\brequire\b|\$GLOBALS|\$_[A-Z]+|`/', $code);
    }

    private static function compile(string $source): \OrillaCart\Scripting\Application\Dto\Compiled
    {
        $stub = static fn(mixed ...$args): mixed => $args;
        $functions = [...new Builtins()->functions(), ...new TimeFunctions(new \Symfony\Component\Clock\MockClock())->functions()];
        $functions[] = new HostFunction('http.post', $stub, 2, 2, '', 'http', HostFunction::HTTP);
        $functions[] = new HostFunction('shop.orders.find', $stub, 1, 1, '', 'orders:read', HostFunction::QUERY);
        $functions[] = new HostFunction('objects.*.query', $stub, 1, 1, '');
        $functions[] = new HostFunction('log.info', $stub, 1, 2, '');

        try {
            $program = Parser::parse($source . "\n");
        } catch (\OrillaCart\Scripting\Domain\SyntaxError $e) {
            return new \OrillaCart\Scripting\Application\Dto\Compiled('', null, [new \OrillaCart\Scripting\Application\Dto\Finding($e->sourceLine, $e->sourceColumn, $e->getMessage())]);
        }

        return new Compiler(HostCatalog::of($functions))->compile($program, 'test_' . substr(md5($source), 0, 8));
    }
}
