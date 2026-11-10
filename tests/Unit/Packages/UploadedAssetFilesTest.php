<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Packages\Infrastructure\WordPress\UploadedAssetFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Publishing an asset removes its own older versions only, never another asset whose name starts the same. */
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
}
