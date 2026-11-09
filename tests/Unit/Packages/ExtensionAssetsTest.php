<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Packages\Application\AssetFiles;
use OrillaCart\Packages\Application\ExtensionAssets;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Platform\Twig\ExtensionAssetsExtension;
use OrillaCart\Platform\Twig\TwigTemplateWords;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/** Design 59: an extension's scripts and stylesheets, printed by asset() in its templates and served as files. */
final class ExtensionAssetsTest extends TestCase
{
    private Package $package;
    private ExtensionAssets $assets;
    /** @var list<array{string, string, string}> */
    private array $published = [];
    /** @var list<string> */
    private array $forgotten = [];
    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $at = new \DateTimeImmutable('2026-11-09');
        $this->package = new Package('loyalty', 'Loyalty', $at);
        $this->package->write('main.ocs', "extension \"loyalty\" { needs: { assets: serve } }\n", $at);
        $this->package->write('assets/portal.js', 'console.log(1)', $at);
        $this->package->write('assets/portal.css', '.portal{}', $at);
        $this->package->write('assets/icons/star.svg', '<svg/>', $at);
        $repository = new class ($this->package) implements PackageRepository {
            public function __construct(private readonly Package $package) {}

            public function all(): array
            {
                return [$this->package];
            }

            public function enabled(): array
            {
                return [$this->package];
            }

            public function find(string $slug): ?Package
            {
                return $slug === $this->package->slug() ? $this->package : null;
            }

            public function save(Package $package): void {}

            public function remove(Package $package): void {}
        };
        $files = new class (function (string $extension, string $name, string $content): void {
            $this->published[] = [$extension, $name, $content];
        }, function (string $extension): void {
            $this->forgotten[] = $extension;
        }) implements AssetFiles {
            public function __construct(private readonly \Closure $record, private readonly \Closure $drop) {}

            public function publish(string $extension, string $name, string $content): string
            {
                ($this->record)($extension, $name, $content);

                return "https://shop.example/uploads/orillacart/extensions/{$extension}/" . preg_replace('/(\.[a-z]+)$/', '-' . substr(md5($content), 0, 6) . '$1', $name);
            }

            public function forget(string $extension): void
            {
                ($this->drop)($extension);
            }
        };
        $logger = new class (function (string $line): void {
            $this->warnings[] = $line;
        }) extends AbstractLogger {
            public function __construct(private readonly \Closure $record) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->record)(strtr((string) $message, array_combine(array_map(static fn(string $key): string => '{' . $key . '}', array_keys($context)), array_map(static fn(mixed $value): string => \is_scalar($value) ? (string) $value : '', $context))));
            }
        };
        $this->assets = new ExtensionAssets($repository, $files, $logger);
    }

    public function testAnEnabledExtensionsFilesArePrintedAsTagsOnceAndServed(): void
    {
        $this->package->enable(['assets'], ['assets'], new \DateTimeImmutable('2026-11-09'));

        $script = $this->assets->tag('loyalty', 'portal.js');
        self::assertMatchesRegularExpression('#^<script src="https://shop.example/uploads/orillacart/extensions/loyalty/portal-[0-9a-f]{6}\.js" defer></script>$#', $script);
        self::assertSame('<link rel="stylesheet" href="https://shop.example/uploads/orillacart/extensions/loyalty/portal-' . substr(md5('.portal{}'), 0, 6) . '.css">', $this->assets->tag('loyalty', 'portal.css'));
        self::assertSame('', $this->assets->tag('loyalty', 'portal.js'), 'printed once per page');
        self::assertStringStartsWith('https://shop.example/uploads/orillacart/extensions/loyalty/icons/star-', $this->assets->tag('loyalty', 'icons/star.svg'), 'an SVG is its URL, as often as asked');
        self::assertStringStartsWith('https://', $this->assets->tag('loyalty', 'icons/star.svg'));
        self::assertSame([['loyalty', 'portal.js', 'console.log(1)'], ['loyalty', 'portal.css', '.portal{}'], ['loyalty', 'icons/star.svg', '<svg/>'], ['loyalty', 'icons/star.svg', '<svg/>']], $this->published, 'published as asked, which writes the file once');

        $this->assets->reset();
        self::assertStringStartsWith('<script', $this->assets->tag('loyalty', 'portal.js'), 'a new page prints it again');

        self::assertSame('', $this->assets->tag('loyalty', 'missing.js'));
        self::assertSame('', $this->assets->tag('nobody', 'portal.js'));
        self::assertSame(['The extension "loyalty" has no file assets/missing.js: nothing is served for it.', 'The extension "nobody" is not enabled: its asset portal.js is not served.'], $this->warnings);

        $this->assets->forget('loyalty');
        self::assertSame(['loyalty'], $this->forgotten);
    }

    public function testNothingIsServedWithoutTheGrantOrBeforeEnabling(): void
    {
        self::assertSame('', $this->assets->tag('loyalty', 'portal.js'), 'a draft');
        $this->package->enable([], ['templates'], new \DateTimeImmutable('2026-11-09'));
        self::assertSame('', $this->assets->tag('loyalty', 'portal.js'));
        self::assertSame([], $this->published);
        self::assertStringContainsString('was not granted "assets"', $this->warnings[1]);
    }

    public function testTheFilesATemplateNamesAreReadForTheCheck(): void
    {
        $twig = new Environment(new ArrayLoader());
        $twig->addExtension(new ExtensionAssetsExtension($this->assets));
        $words = new TwigTemplateWords($twig);

        self::assertSame([['name' => 'portal.js', 'line' => 1], ['name' => 'icons/star.svg', 'line' => 2]], $words->assets('x.html.twig', "{{ asset('portal.js') }}\n<img src=\"{{ asset('icons/star.svg') }}\">{{ asset(name) }}"));
        self::assertSame([], $words->assets('x.html.twig', '{{ asset( }}'), 'one that does not compile has none');
    }

    public function testAFileIsNamedUnderAssetsWithAKindTheBrowserTakes(): void
    {
        foreach (['assets/portal.js', 'assets/css/site.css', 'assets/icons/a-b_c.svg'] as $fine) {
            Package::checkPath($fine);
        }
        foreach (['portal.js', 'templates/x.js', 'assets/x.php', 'assets/x.png', 'assets/../x.js'] as $wrong) {
            try {
                Package::checkPath($wrong);
                self::fail($wrong);
            } catch (\OrillaCart\Packages\Domain\PackageRuleViolation $e) {
                self::assertSame('orillacart_invalid_extension_file', $e->errorCode, $wrong);
            }
        }
    }
}
