<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform;

use OrillaCart\Platform\PluginContext;
use PHPUnit\Framework\TestCase;

final class PluginContextTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/orillacart-context-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/migrations', 0o777, true);
        file_put_contents($this->dir . '/orillacart.php', "<?php\n/**\n * Plugin Name: OrillaCart\n * Version:           2.0.0\n */\n");
        touch($this->dir . '/migrations/Version20261001000000.php');
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/migrations/*');
        array_map(unlink(...), $files === false ? [] : $files);
        rmdir($this->dir . '/migrations');
        unlink($this->dir . '/orillacart.php');
        rmdir($this->dir);
    }

    public function testTheFilesAreTheBuildTheProcessStartedWithUntilThePluginIsUpdated(): void
    {
        $context = new PluginContext($this->dir . '/orillacart.php', '2.0.0');

        self::assertSame('2.0.0+20261001000000', $context->build);
        self::assertSame($context->build, $context->filesBuild());

        touch($this->dir . '/migrations/Version20261101000000.php');
        self::assertSame('2.0.0+20261101000000', $context->filesBuild(), 'a new migration');

        unlink($this->dir . '/migrations/Version20261101000000.php');
        file_put_contents($this->dir . '/orillacart.php', "<?php\n/**\n * Plugin Name: OrillaCart\n * Version:           2.0.1\n */\n");
        self::assertSame('2.0.1+20261001000000', $context->filesBuild(), 'a new version');
    }
}
