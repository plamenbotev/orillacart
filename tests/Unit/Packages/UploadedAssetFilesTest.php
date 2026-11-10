<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Packages\Infrastructure\WordPress\UploadedAssetFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Publishing an asset removes its own older versions only, never another asset whose name starts the same; the
 * folder serves only the files the shop writes.
 */
final class UploadedAssetFilesTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, bool}> */
    public static function files(): iterable
    {
        yield 'an older version' => ['a-0123456789ab.js', 'a', '.js', true];
        yield 'another asset with a dash' => ['a-b-0123456789ab.js', 'a', '.js', false];
        yield 'a hash of another length' => ['a-0123456789.js', 'a', '.js', false];
        yield 'not hex' => ['a-0123456789xy.js', 'a', '.js', false];
        yield 'another extension' => ['a-0123456789ab.js.map', 'a', '.js', false];
        yield 'no extension' => ['LICENSE-0123456789ab', 'LICENSE', '', true];
        yield 'a dot in the name is literal' => ['aXb-0123456789ab.js', 'a.b', '.js', false];
    }

    #[DataProvider('files')]
    public function testOnlyVersionsOfTheSameNameMatch(string $file, string $base, string $suffix, bool $matches): void
    {
        self::assertSame($matches, UploadedAssetFiles::isVersionOf($file, $base, $suffix));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function served(): iterable
    {
        yield 'a script' => ['app-0123456789ab.js', true];
        yield 'a minified stylesheet' => ['site.min-0123456789ab.css', true];
        yield 'a versioned SVG' => ['logo.2-0123456789ab.svg', true];
        yield 'PHP' => ['shell.php', false];
        yield 'PHP named like an asset' => ['app-0123456789ab.php', false];
        yield 'PHP under a script\'s name' => ['app.php-0123456789ab.js', false];
        yield 'a script with PHP after it' => ['app-0123456789ab.js.php', false];
        yield 'another handler' => ['page-0123456789ab.shtml', false];
        yield 'HTML' => ['page-0123456789ab.html', false];
        yield 'a script without its hash' => ['app.js', false];
        yield 'the rules themselves' => ['.htaccess', false];
    }

    #[DataProvider('served')]
    public function testTheFolderServesOnlyTheFilesTheShopWrites(string $file, bool $served): void
    {
        self::assertSame(1, preg_match('/<FilesMatch "([^"]+)">/', UploadedAssetFiles::HTACCESS, $match));
        self::assertStringContainsString("Require all denied\n", UploadedAssetFiles::HTACCESS, 'everything else is refused');

        self::assertSame($served, preg_match('/' . str_replace('/', '\\/', $match[1]) . '/', $file) === 1);
    }
}
