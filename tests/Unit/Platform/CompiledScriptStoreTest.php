<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Scripting\CompiledScriptStore;
use OrillaCart\Shared\Security\Signatures;
use PHPUnit\Framework\TestCase;

/**
 * The compiled-class store without a cache folder (no WordPress here): what it keeps is signed, what it reads
 * back has to carry this site's signature, and what the gate refuses is never evaluated. The files themselves
 * are tried in the integration suite (CompiledScriptStoreTest there).
 */
final class CompiledScriptStoreTest extends TestCase
{
    private const string SOURCE = "<?php\n\ndeclare(strict_types=1);\n\nnamespace OrillaCart\\Generated\\Scripts;\n\n/** Generated from a script package: do not edit. */\nfinal class Script_store_unit extends \\OrillaCart\\Scripting\\Application\\CompiledScript\n{\n    public static function manifest(): array\n    {\n        return [];\n    }\n}\n";

    public function testSignsWhatItKeepsAndReadsOnlyItsOwnSignatureBack(): void
    {
        $store = self::store('secret-a');

        $signed = $store->signed(self::SOURCE);
        self::assertStringStartsWith(self::SOURCE . "\n// signed ", $signed);
        self::assertSame(self::SOURCE, $store->unsigned($signed));
        self::assertNull($store->unsigned(self::SOURCE), 'no signature');
        self::assertNull($store->unsigned(str_replace('Script_store_unit', 'Script_other', $signed)), 'the source changed after signing');
        self::assertNull(self::store('secret-b')->unsigned($signed), 'another site\'s signature');
    }

    public function testRefusesToEvaluateWhatTheGateRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The compiled code was refused: the word system at line 2');
        self::store('secret-a')->put('k', 'X', "<?php\nsystem('id');\n");
    }

    public function testEvaluatesInMemoryWithoutAFolder(): void
    {
        self::store('secret-a')->put('store-unit', 'OrillaCart\\Generated\\Scripts\\Script_store_unit', self::SOURCE);

        self::assertTrue(class_exists('OrillaCart\\Generated\\Scripts\\Script_store_unit', false));
    }

    private static function store(string $secret): CompiledScriptStore
    {
        return new CompiledScriptStore(new PluginContext(__DIR__ . '/orillacart.php', '0.0.0-test'), new Signatures($secret));
    }
}
