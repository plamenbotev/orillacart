<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Packages\Application\Dto\PackageExport;
use OrillaCart\Packages\Domain\PackageFile;
use OrillaCart\Packages\Domain\PackageRuleViolation;
use OrillaCart\Packages\Infrastructure\ZipPackageArchives;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/** Design 58: an extension's zip read into memory with the package's own checks, and written with extension.json. */
#[RequiresPhpExtension('zip')]
final class ZipPackageArchivesTest extends TestCase
{
    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAnExportIsReadBackWithItsManifest(): void
    {
        $bytes = new ZipPackageArchives()->write(new PackageExport('greeter', 'Greeter', '1.2.0', ['main.ocs' => "fn a() { return 1 }\n", 'templates/note.html.twig' => '<p>hi</p>'], translations: ['abc' => ['de_DE' => ['Hallo']]]));
        $path = $this->file($bytes);

        $archive = new ZipPackageArchives()->read($path);
        self::assertSame(['main.ocs' => "fn a() { return 1 }\n", 'templates/note.html.twig' => '<p>hi</p>'], $archive->files);
        self::assertSame(['format' => 'orillacart-extension/1', 'id' => 'greeter', 'name' => 'Greeter', 'version' => '1.2.0', 'translations' => ['abc' => ['de_DE' => ['Hallo']]]], $archive->manifest);
        self::assertSame([], $archive->skipped);
    }

    public function testTheOneFolderAroundTheFilesIsLeftOutAndWhatIsNotAnExtensionsIsSkipped(): void
    {
        $archive = new ZipPackageArchives()->read($this->zip([
            'payfast-main/' => null,
            'payfast-main/main.ocs' => 'fn a() { return 1 }',
            'payfast-main/templates/x.html.twig' => 'x',
            'payfast-main/README.md' => '# PayFast',
            'payfast-main/assets/portal.js' => 'x',
            'payfast-main/webpack.config.js' => 'x',
            'payfast-main/LICENSE' => 'MIT',
            'payfast-main/.DS_Store' => "\0\1",
            'payfast-main/.git/config' => '[core]',
            '__MACOSX/payfast-main/._main.ocs' => "\0",
        ]));

        self::assertSame(['README.md', 'assets/portal.js', 'main.ocs', 'templates/x.html.twig'], array_keys($archive->files));
        self::assertSame(['payfast-main/webpack.config.js', 'payfast-main/LICENSE'], $archive->skipped, 'hidden files go without a word; a script outside assets/ is a tool\'s');
        self::assertNull($archive->manifest);
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string, array<string, int>}>
     */
    public static function refused(): iterable
    {
        yield 'a path that climbs' => [['../evil.ocs' => 'x'], 'orillacart_invalid_extension_file', []];
        yield 'a name the package does not take' => [['my file.ocs' => 'x'], 'orillacart_invalid_extension_file', []];
        yield 'a file too large' => [['main.ocs' => str_repeat('a', PackageFile::MAX_CONTENT_LENGTH + 1)], 'orillacart_extension_file_too_large', []];
        yield 'bytes that are not text' => [['main.ocs' => "\xff\xfe"], 'orillacart_extension_zip_not_text', []];
        yield 'a link' => [['main.ocs' => '/etc/passwd'], 'orillacart_extension_zip_link', ['main.ocs' => 0o120777]];
        yield 'extension.json that is not JSON' => [['extension.json' => '{', 'main.ocs' => 'x'], 'orillacart_extension_zip_manifest', []];
        yield 'extension.json with an id that is not text' => [['extension.json' => '{"id": 5}'], 'orillacart_extension_zip_manifest', []];
        yield 'more files than a package holds' => [array_combine(array_map(static fn(int $i): string => "f{$i}.ocs", range(1, 51)), array_fill(0, 51, 'x')), 'orillacart_extension_too_many_files', []];
        yield 'more entries than a zip may have' => [array_combine(array_map(static fn(int $i): string => "f{$i}.txt", range(1, 501)), array_fill(0, 501, 'x')), 'orillacart_extension_zip_too_many', []];
    }

    /**
     * @param array<string, string|null> $entries
     * @param array<string, int>         $modes
     */
    #[DataProvider('refused')]
    public function testARefusedZipSaysWhy(array $entries, string $code, array $modes): void
    {
        try {
            new ZipPackageArchives()->read($this->zip($entries, $modes));
            self::fail('refused');
        } catch (PackageRuleViolation $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    public function testAFileThatIsNotAZipIsRefused(): void
    {
        $this->expectExceptionMessage('The file is not a zip that opens.');
        new ZipPackageArchives()->read($this->file('not a zip'));
    }

    /**
     * @param array<string, string|null> $entries a folder as null
     * @param array<string, int>         $modes   Unix modes of entries
     */
    private function zip(array $entries, array $modes = []): string
    {
        $path = $this->file('');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $content === null ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $content);
            if (isset($modes[$name])) {
                $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, $modes[$name] << 16);
            }
        }
        $zip->close();

        return $path;
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'oc-zip-test');
        file_put_contents($path, $bytes);
        $this->made[] = $path;

        return $path;
    }
}
