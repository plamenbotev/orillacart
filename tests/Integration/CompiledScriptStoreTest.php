<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\CacheFolders;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Scripting\CompiledScriptStore;
use OrillaCart\Platform\WordPress\Security\SiteSignatures;
use OrillaCart\Scripting\Application\Scripts;

/**
 * Compiled classes as files under the cache folder: signed when written, checked before included, and a file
 * that was planted, edited or signed by another site is thrown away and the class compiled again.
 */
final class CompiledScriptStoreTest extends RestTestCase
{
    public function testAFileIsSignedAndAnEditedOrPlantedOneIsNotIncluded(): void
    {
        $container = self::kernel()->container();
        $scripts = $container->get(Scripts::class);
        $context = $container->get(PluginContext::class);
        \assert($scripts instanceof Scripts && $context instanceof PluginContext);
        $store = new CompiledScriptStore($context, SiteSignatures::create());
        $source = "fn f() { return 'signed' }\n";
        $scope = 'store-test/' . bin2hex(random_bytes(4));
        $key = preg_replace('/[^A-Za-z0-9]+/', '_', $scope) . '-' . Scripts::checksum($source);

        self::assertSame('signed', $scripts->load($source, $scope)->run('fn_f'));
        $folder = CacheFolders::path('scripts', $context->build);
        self::assertNotNull($folder, 'the cache folder is writable here');
        $path = $folder . '/' . $key . '.php';
        $file = (string) file_get_contents($path);
        self::assertMatchesRegularExpression('/\n\/\/ signed [0-9a-f]{32}\n$/', $file);
        self::assertTrue($store->load($key, 'OrillaCart\\Generated\\Scripts\\Script_' . str_replace('-', '_', $key)));

        // Edited after signing: the signature no longer matches, the file goes, and the class is not defined by it.
        file_put_contents($path, str_replace("'signed'", "'edited'", $file));
        self::assertFalse($store->load($key, 'OrillaCart\\Generated\\Scripts\\Script_not_this'));
        self::assertFileDoesNotExist($path);

        // Planted: well-formed PHP with the right name but no signature.
        file_put_contents($path, "<?php\nnamespace OrillaCart\\Generated\\Scripts;\nfile_put_contents('/tmp/pwned', '1');\n");
        self::assertFalse($store->load($key, 'OrillaCart\\Generated\\Scripts\\Script_not_this'));
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist('/tmp/pwned');

        // Signed, but the code is outside the compiler's grammar: refused by the gate as well.
        file_put_contents($path, $store->signed("<?php\nnamespace OrillaCart\\Generated\\Scripts;\nfile_put_contents('/tmp/pwned', '1');\n"));
        self::assertFalse($store->load($key, 'OrillaCart\\Generated\\Scripts\\Script_not_this'));
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist('/tmp/pwned');
    }
}
