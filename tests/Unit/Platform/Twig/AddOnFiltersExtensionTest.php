<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Twig;

use OrillaCart\Extension\Storefront\TemplateFilter;
use OrillaCart\Platform\Twig\AddOnFiltersExtension;
use OrillaCart\Platform\Twig\StorefrontSandbox;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use OrillaCart\Tests\Fixtures\Payment\FixedExtensions;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityError;

final class AddOnFiltersExtensionTest extends TestCase
{
    public function testAnAddOnsFilterReachesSandboxedTemplatesAndNeverReplacesTheShops(): void
    {
        $logger = new MemoryLogger();
        $twig = new Environment(new ArrayLoader(['page' => '{{ "hi"|shout }} {{ "a"|upper }}', 'other' => '{{ "x"|convert_encoding("UTF-8", "ISO-8859-1") }}']));
        $policy = StorefrontSandbox::policy();
        $twig->addExtension(new SandboxExtension($policy, true));
        $installed = new FixedExtensions();
        $installed->templateFilters = [self::filter('shout', 'HI!'), self::filter('upper', 'taken')];
        $twig->addExtension(new AddOnFiltersExtension($installed, $twig, $policy, $logger));

        self::assertSame('HI! A', $twig->render('page'), 'the add-on\'s filter, in the sandbox; Twig\'s own "upper" is kept');
        self::assertSame('An add-on\'s template filter is named "{name}", as one of the shop\'s; it is left out.', $logger->records[0]['message'] ?? null);
        self::assertSame(['name' => 'upper'], $logger->records[0]['context'] ?? null);
        $this->expectException(SecurityError::class);
        $twig->render('other');
    }

    private static function filter(string $name, string $answer): TemplateFilter
    {
        return new class ($name, $answer) implements TemplateFilter {
            public function __construct(private string $name, private string $answer) {}

            public function name(): string
            {
                return $this->name;
            }

            public function apply(mixed $value, array $arguments): mixed
            {
                return $this->answer;
            }

            public function isHtml(): bool
            {
                return false;
            }
        };
    }
}
