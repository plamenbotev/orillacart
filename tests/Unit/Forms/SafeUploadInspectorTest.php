<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Forms;

use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Forms\Application\TemporaryUploads;
use OrillaCart\Forms\Domain\FormRuleViolation;
use OrillaCart\Forms\Infrastructure\SafeUploadInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeUploadInspectorTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testTellsTheTypeFromTheContentNotTheName(): void
    {
        $inspector = new SafeUploadInspector();

        self::assertSame('application/pdf', $inspector->type($this->file("%PDF-1.4\n%%EOF\n", '.png')));
        self::assertSame('text/html', $inspector->type($this->file('<html><script>alert(1)</script></html>', '.jpg')));
    }

    public function testAPlainPdfIsCopied(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n%%EOF\n";
        $clean = new SafeUploadInspector()->clean($this->file($pdf), 'application/pdf');
        $this->paths[] = $clean;

        self::assertSame($pdf, file_get_contents($clean));
    }

    public function testTheCleanCopyIsATemporaryFileOfTheSite(): void
    {
        $prefix = FormUploads::temporaryPrefix(3);
        self::assertSame('orillacart-upload-3-', $prefix);
        self::assertStringStartsNotWith($prefix, FormUploads::temporaryPrefix(31), 'site 31\'s files are not site 3\'s');
        $temporaries = new class ($prefix) implements TemporaryUploads {
            public function __construct(private readonly string $prefix) {}

            public function prefix(): string
            {
                return $this->prefix;
            }

            public function create(): string
            {
                return (string) tempnam(sys_get_temp_dir(), $this->prefix);
            }
        };

        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n%%EOF\n";
        $clean = new SafeUploadInspector($temporaries)->clean($this->file($pdf), 'application/pdf');
        $this->paths[] = $clean;

        self::assertStringStartsWith($prefix, basename($clean));
        self::assertSame($pdf, file_get_contents($clean));
    }

    public function testAPdfWithCompressedPagesAndPicturesIsTaken(): void
    {
        $pdf = "%PDF-1.5\n4 0 obj << /Length 20 /Filter /FlateDecode >>\nstream\n" . gzcompress('BT /F1 12 Tf (Hello) Tj ET') . "\nendstream\nendobj\n"
            . "5 0 obj << /Type /XObject /Subtype /Image /Width 1 /Height 1 /Filter /DCTDecode >>\nstream\n\xFF\xD8\xFF\xE0 jpeg \xFF\xD9\nendstream\nendobj\n%%EOF\n";
        $clean = new SafeUploadInspector()->clean($this->file($pdf), 'application/pdf');
        $this->paths[] = $clean;

        self::assertSame($pdf, file_get_contents($clean));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actingPdfs(): iterable
    {
        yield 'script' => ["%PDF-1.4\n1 0 obj << /Type /Action /S /JavaScript /JS (app.alert(1)) >> endobj"];
        yield 'on opening' => ["%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction 2 0 R >> endobj"];
        yield 'launch' => ["%PDF-1.4\n1 0 obj << /S /Launch /F (cmd.exe) >> endobj"];
        yield 'attachment' => ["%PDF-1.4\n1 0 obj << /EmbeddedFiles 3 0 R >> endobj"];
        yield 'escaped name' => ["%PDF-1.4\n1 0 obj << /S /J#61vaScript >> endobj"];
        yield 'encrypted' => ["%PDF-1.4\ntrailer << /Encrypt 5 0 R >>"];
        yield 'form' => ["%PDF-1.4\n1 0 obj << /AcroForm 4 0 R >> endobj"];
        yield 'in a compressed stream' => ["%PDF-1.5\n1 0 obj << /Type /ObjStm /Filter /FlateDecode >>\nstream\n" . gzcompress('<< /S /JavaScript /JS (x) >>') . "\nendstream\nendobj"];
        yield 'not a PDF' => ['<html>%PDF-1.4</html>'];
        yield 'too big to unpack' => ["%PDF-1.5\n1 0 obj << /Type /ObjStm /Filter /FlateDecode >>\nstream\n" . self::bomb() . "\nendstream\nendobj"];
        yield 'another encoding' => ["%PDF-1.5\n1 0 obj << /Filter /ASCIIHexDecode >>\nstream\n2F4A532028782920>\nendstream\nendobj"];
        yield 'encodings in a chain' => ["%PDF-1.5\n1 0 obj << /Filter [/ASCII85Decode /FlateDecode] >>\nstream\nabc\nendstream\nendobj"];
        yield 'a filter behind a nested dictionary' => ["%PDF-1.5\n1 0 obj << /DecodeParms << /Predictor 1 >> /F#69lter /ASCII85Decode >>\nstream\nabc\nendstream\nendobj"];
        yield 'cut short by a false end' => ["%PDF-1.5\n1 0 obj << /Filter /FlateDecode >>\nstream\n" . substr((string) gzcompress(str_repeat('q ', 500) . '<< /S /Launch >>'), 0, 8) . "endstream\nendobj"];
        yield 'a stream that does not unpack' => ["%PDF-1.5\n1 0 obj << /Filter /FlateDecode >>\nstream\nnot zlib at all\nendstream\nendobj"];
        yield 'a stream without its object' => ["%PDF-1.5\n<< /Filter /FlateDecode >>\nstream\n" . gzcompress('BT ET') . "\nendstream"];
    }

    #[DataProvider('actingPdfs')]
    public function testRefusesAPdfThatActs(string $pdf): void
    {
        $this->expectException(FormRuleViolation::class);
        new SafeUploadInspector()->clean($this->file($pdf), 'application/pdf');
    }

    public function testAPictureIsWrittenAgainWithoutWhatHidAfterIt(): void
    {
        if (!new SafeUploadInspector()->takesImages()) {
            self::markTestSkipped('GD is not installed.');
        }
        $image = imagecreatetruecolor(4, 3);
        self::assertNotFalse($image);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $clean = new SafeUploadInspector()->clean($this->file($png . '<?php system($_GET["c"]); ?>'), 'image/png');
        $this->paths[] = $clean;

        $bytes = (string) file_get_contents($clean);
        self::assertStringNotContainsString('<?php', $bytes);
        self::assertSame([4, 3], \array_slice((array) getimagesize($clean), 0, 2));
    }

    public function testRefusesAPictureThatIsNotOne(): void
    {
        if (!new SafeUploadInspector()->takesImages()) {
            self::markTestSkipped('GD is not installed.');
        }
        $this->expectException(FormRuleViolation::class);
        new SafeUploadInspector()->clean($this->file("\x89PNG\r\n\x1a\nnot really"), 'image/png');
    }

    /** 65 MB of spaces after a script, packed a megabyte at a time: about 65 KB, never 65 MB in memory. */
    private static function bomb(): string
    {
        $deflater = deflate_init(\ZLIB_ENCODING_DEFLATE);
        self::assertNotFalse($deflater);
        $packed = (string) deflate_add($deflater, str_repeat(' ', 1024 * 1024) . '<< /OpenAction << /S /JavaScript /JS (x) >> >>', \ZLIB_NO_FLUSH);
        $megabyte = str_repeat(' ', 1024 * 1024);
        for ($i = 0; $i < 65; ++$i) {
            $packed .= (string) deflate_add($deflater, $megabyte, \ZLIB_NO_FLUSH);
        }

        return $packed . (string) deflate_add($deflater, '', \ZLIB_FINISH);
    }

    private function file(string $bytes, string $suffix = ''): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'inspect') . $suffix;
        file_put_contents($path, $bytes);
        $this->paths[] = $path;

        return $path;
    }
}
