<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Forms;

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

    private function file(string $bytes, string $suffix = ''): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'inspect') . $suffix;
        file_put_contents($path, $bytes);
        $this->paths[] = $path;

        return $path;
    }
}
