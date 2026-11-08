<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Scripting;

use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use PHPUnit\Framework\TestCase;

/** Calling a script's function with the wrong number of arguments is the script's failure, not a PHP error. */
final class RunArityTest extends TestCase
{
    public function testTooFewOrTooManyArgumentsAreAScriptError(): void
    {
        $catalog = HostCatalog::of(new Builtins()->functions());
        $scripts = new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore());
        $script = $scripts->load("fn hello(who) { return 'hi ' ~ who }\nfn pair(a, b) { return [a, b] }\nfn none() { return 1 }\n", 'arity');

        self::assertSame('hi Ada', $script->run('fn_hello', 'Ada'));
        self::assertSame(1, $script->run('fn_none'));
        self::assertStringStartsWith('OrillaCart\\Generated\\Scripts\\Site1\\Script_arity_', $script::class);

        foreach ([['fn_hello', [], 'hello expects 1 argument, 0 given'], ['fn_hello', [1, 2], 'hello expects 1 argument, 2 given'], ['fn_pair', [1], 'pair expects 2 arguments, 1 given'], ['fn_none', [1], 'none expects 0 arguments, 1 given']] as [$method, $args, $message]) {
            try {
                $script->run($method, ...$args);
                self::fail($message);
            } catch (ScriptError $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }
}
