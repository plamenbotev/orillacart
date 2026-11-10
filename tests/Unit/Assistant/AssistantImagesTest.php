<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant;

use OrillaCart\Assistant\Infrastructure\WordPress\AssistantImages;
use PHPUnit\Framework\TestCase;

final class AssistantImagesTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    public function testAPictureNamedForWhatItIsIsTaken(): void
    {
        $png = $this->file((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true));
        $gif = $this->file((string) base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==', true));

        self::assertSame('image/png', AssistantImages::type($png, 'dot.PNG'));
        self::assertSame('image/gif', AssistantImages::type($gif, 'dot.gif'));
    }

    public function testAnSvgIsNotTakenHoweverItIsNamed(): void
    {
        $svg = $this->file('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        self::assertNull(AssistantImages::type($svg, 'logo.svg'));
        self::assertNull(AssistantImages::type($svg, 'logo.png'));
    }

    public function testAPictureNamedAsAnotherTypeOrAsNoneIsNotTaken(): void
    {
        $png = $this->file((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true));

        self::assertNull(AssistantImages::type($png, 'dot.jpg'));
        self::assertNull(AssistantImages::type($png, 'dot'));
        self::assertNull(AssistantImages::type($png, 'dot.ico'));
        self::assertNull(AssistantImages::type($png, 'dot.png.php'));
    }

    public function testAFileThatOnlyStartsLikeAPictureIsNotTaken(): void
    {
        self::assertNull(AssistantImages::type($this->file("\x89PNG\r\n\x1A\n<?php echo 1;"), 'dot.png'));
        self::assertNull(AssistantImages::type($this->file('hello'), 'note.webp'));
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'assistant-image');
        file_put_contents($path, $bytes);
        $this->paths[] = $path;

        return $path;
    }
}
