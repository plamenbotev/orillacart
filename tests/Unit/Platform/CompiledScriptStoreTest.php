<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Scripting\CompiledScriptStore;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Shared\Security\Signatures;
use PHPUnit\Framework\TestCase;

/**
 * The compiled-class store without a cache folder (no WordPress here): what it keeps is signed, what it reads
 * back has to carry this site's signature of that key and build, and what the gate refuses is never evaluated.
 * The cache files themselves are tried in the integration suite (CompiledScriptStoreTest there).
 */
final class CompiledScriptStoreTest extends TestCase
{
    private const string SOURCE = "<?php\n\ndeclare(strict_types=1);\n\nnamespace OrillaCart\\Generated\\Scripts\\Site1;\n\n/** Generated from a script package: do not edit. */\nfinal class Script_store_unit extends \\OrillaCart\\Scripting\\Application\\CompiledScript\n{\n    public static function manifest(): array\n    {\n        return [];\n    }\n}\n";

    public function testSignsWhatItKeepsAndReadsOnlyItsOwnSignatureBack(): void
    {
        $store = self::store('secret-a');

        $signed = $store->signed('s1-unit-k', self::SOURCE);
        self::assertStringStartsWith(self::SOURCE . "\n// signed ", $signed);
        self::assertSame(self::SOURCE, $store->unsigned('s1-unit-k', $signed));
        self::assertNull($store->unsigned('s1-unit-k', self::SOURCE), 'no signature');
        self::assertNull($store->unsigned('s1-unit-k', str_replace('Script_store_unit', 'Script_other', $signed)), 'the source changed after signing');
        self::assertNull(self::store('secret-b')->unsigned('s1-unit-k', $signed), 'another site\'s signature');
    }

    public function testASignedFileMovedToAnotherKeyOrBuildIsRefused(): void
    {
        $signed = self::store('secret-a')->signed('s1-unit-k', self::SOURCE);

        self::assertNull(self::store('secret-a')->unsigned('s1-unit-other', $signed), 'another key');
        self::assertNull(self::store('secret-a', '0.0.1-test')->unsigned('s1-unit-k', $signed), 'another build');
    }

    public function testAClassAndItsKeyGoByTheWholeChecksumOfTheSource(): void
    {
        $checksum = Scripts::checksum("fn f() { return 1 }\n");

        self::assertSame(hash('sha256', "fn f() { return 1 }\n"), $checksum, 'all 256 bits: a collision is not within reach');
        self::assertStringEndsWith('-' . $checksum, Scripts::key('1', 'unit', "fn f() { return 1 }\n"));
    }

    public function testRefusesToEvaluateWhatTheGateRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The compiled code was refused: the word system at line 2');
        self::store('secret-a')->put('k', 'X', "<?php\nsystem('id');\n");
    }

    public function testRefusesAnotherSitesClassEvenWhenItIsWellFormed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('the namespace of site 7, not site 1');
        self::store('secret-a')->put('k', 'X', str_replace('Site1', 'Site7', self::SOURCE));
    }

    public function testEvaluatesInMemoryWithoutAFolder(): void
    {
        self::store('secret-a')->put('store-unit', 'OrillaCart\\Generated\\Scripts\\Site1\\Script_store_unit', self::SOURCE);

        self::assertTrue(class_exists('OrillaCart\\Generated\\Scripts\\Site1\\Script_store_unit', false));
    }

    private static function store(string $secret, string $version = '0.0.0-test'): CompiledScriptStore
    {
        return new CompiledScriptStore(new PluginContext(__DIR__ . '/orillacart.php', $version), new Signatures($secret));
    }
}
