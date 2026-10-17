<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Twig;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Twig\TwigFactory;
use OrillaCart\Platform\Twig\TwigRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TwigFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/orillacart-twig-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/plugin/templates', 0o777, true);
        mkdir($this->dir . '/theme/orillacart', 0o777, true);
        file_put_contents($this->dir . '/plugin/orillacart.php', '');
        file_put_contents($this->dir . '/plugin/templates/product.twig', 'plugin: {{ name }}');
        file_put_contents($this->dir . '/plugin/templates/cart.twig', 'cart: {{ name }}');
        file_put_contents($this->dir . '/theme/orillacart/product.twig', 'theme: {{ name }}');
    }

    protected function tearDown(): void
    {
        foreach (['plugin/templates/product.twig', 'plugin/templates/cart.twig', 'plugin/orillacart.php', 'theme/orillacart/product.twig'] as $file) {
            unlink($this->dir . '/' . $file);
        }
        foreach (['plugin/templates', 'plugin', 'theme/orillacart', 'theme', ''] as $folder) {
            rmdir($this->dir . '/' . $folder);
        }
    }

    public function testWithoutWordPressOnlyThePluginTemplatesAreSearched(): void
    {
        $factory = new TwigFactory(new PluginContext($this->dir . '/plugin/orillacart.php', '1.0.0'));

        self::assertSame([$this->dir . '/plugin/templates'], $factory->templatePaths());
        self::assertSame('plugin: Mug', $factory->create()->render('product.twig', ['name' => 'Mug']));
    }

    public function testThemeTemplatesWinAndOutputIsEscaped(): void
    {
        $renderer = new TwigRenderer(new Environment(
            new FilesystemLoader([$this->dir . '/theme/orillacart', $this->dir . '/plugin/templates']),
            ['autoescape' => 'html', 'strict_variables' => true],
        ));

        self::assertSame('theme: &lt;script&gt;', $renderer->render('product.twig', ['name' => '<script>']));
        self::assertSame('cart: Mug', $renderer->render('cart.twig', ['name' => 'Mug']));
    }
}
