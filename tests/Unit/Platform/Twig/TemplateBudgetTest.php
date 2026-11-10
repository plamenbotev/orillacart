<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Twig;

use OrillaCart\Packages\Application\PackageTemplates;
use OrillaCart\Platform\Twig\PackageTemplateLoader;
use OrillaCart\Platform\Twig\StorefrontSandbox;
use OrillaCart\Platform\Twig\TemplateBudgetExceeded;
use OrillaCart\Platform\Twig\TemplateBudgetExtension;
use OrillaCart\Platform\Twig\TwigRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;

/** An extension's template renders on a budget: capped ranges and formats, and loop turns counted per render. */
final class TemplateBudgetTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function overBudget(): iterable
    {
        yield 'a long range' => ['{% for i in range(1, 1000000000) %}{% endfor %}', 'A range holds at most 10000 numbers'];
        yield 'a long ..' => ['{% for i in 1..1000000000 %}{% endfor %}', 'A range holds at most 10000 numbers'];
        yield 'a range with no step' => ['{{ range(1, 2, 0)|length }}', 'A range holds at most'];
        yield 'nested loops' => ['{% for i in 1..1000 %}{% for j in 1..1000 %}{% endfor %}{% endfor %}', 'more than 100000 loop turns'];
        yield 'arrow functions' => ['{{ range(1, 10000)|map(i => range(1, 10000)|map(j => j)|length)|length }}', 'more than 100000 loop turns'];
        yield 'a macro calling itself' => ['{% macro m(n) %}{{ _self.m(n) }}{% endmacro %}{{ _self.m(1) }}', 'The extension\'s template'];
        yield 'huge decimals' => ['{{ 1|number_format(2000000000) }}', 'number_format takes 0 to 20 decimals'];
        yield 'a huge field' => ['{{ "%01000000000d"|format(1) }}', 'format takes fields of at most 1000 characters'];
        yield 'a doubling string' => ['{% set s = "x" %}{% for i in 1..40 %}{% set s = s ~ s %}{% endfor %}{{ s|length }}', 'took more than 32 MB'];
        yield 'a string doubled with no loop' => ['{% set x = "xx" %}' . str_repeat('{% set x = x ~ x %}', 26) . '{{ x|length }}', 'took more than 32 MB'];
        yield 'a string doubled in one print' => ['{% set x = "' . str_repeat('x', 1024) . '" %}{% set x = x ~ x ~ x ~ x %}{% set x = x ~ x ~ x ~ x %}{% set x = x ~ x ~ x ~ x %}{% set x = x ~ x ~ x ~ x %}{% set x = x ~ x ~ x ~ x %}{{ (x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x ~ x)|length }}', 'took more than 32 MB'];
        yield 'replace growing a string' => ['{% set x = "' . str_repeat('a', 1000) . '" %}{% set x = x|replace({a: "' . str_repeat('b', 1000) . '"}) %}{% set x = x|replace({b: "' . str_repeat('c', 100) . '"}) %}{{ x|length }}', 'took more than 32 MB'];
        yield 'a list doubled with no loop' => ['{% set x = [1] %}' . str_repeat('{% set x = x|merge(x) %}', 26) . '{{ x|length }}', 'took more than 32 MB'];
        yield 'a template including itself' => ['{{ include("x/greeter/t.html.twig") }}', 'nest more than 32 deep'];
        yield 'a template including itself by tag' => ['{% include "x/greeter/t.html.twig" %}', 'nest more than 32 deep'];
        yield 'a block inside itself' => ['{% block a %}{{ block("a") }}{% endblock %}', 'nest more than 32 deep'];
    }

    #[DataProvider('overBudget')]
    public function testAnExtensionsTemplateOverItsBudgetStops(string $source, string $message): void
    {
        $twig = self::twig(['x/greeter/t.html.twig' => $source]);

        $this->expectException(TemplateBudgetExceeded::class);
        $this->expectExceptionMessage($message);
        $twig->render('x/greeter/t.html.twig');
    }

    public function testWithinTheBudgetItRendersAsTwigDoes(): void
    {
        $twig = self::twig(['x/greeter/t.html.twig' => '{{ range(1, 3)|join(",") }} {{ (1..3)|join(",") }} {{ range("a", "c")|join }} {{ 1234.5|number_format(2, ",", ".") }} {{ 1234.5|number_format }} {{ "%05.1f"|format(2.5) }} {{ [1, 2]|map(i => i * 2)|join }} {% for i in 1..2 %}{{ i }}{% endfor %}']);

        self::assertSame('1,2,3 1,2,3 abc 1.234,50 1,235 002.5 24 12', $twig->render('x/greeter/t.html.twig'));
    }

    public function testIncludesBlocksAndJoinsWithinTheBudgetRenderAsTwigDoes(): void
    {
        $twig = self::twig([
            'x/greeter/t.html.twig' => '{% extends "x/greeter/base.html.twig" %}{% block body %}{{ "a" ~ 1 ~ null ~ true }} {{ "abc"|replace({b: "-"}) }} {% include "x/greeter/part.html.twig" %}{% endblock %}',
            'x/greeter/base.html.twig' => '<{% block body %}{% endblock %}>',
            'x/greeter/part.html.twig' => '{% set n = 2 %}{{ n }}',
        ]);

        self::assertSame('<a11 a-c 2>', $twig->render('x/greeter/t.html.twig'));
    }

    public function testTheShopsOwnTemplatesAreLeftAlone(): void
    {
        $twig = self::twig([], ['plugin.html.twig' => '{{ range(1, 20000)|length }} {{ 1|number_format(30)|length }}']);

        self::assertSame('20000 32', $twig->render('plugin.html.twig'));
    }

    public function testEachRenderOfTheRendererHasItsOwnBudget(): void
    {
        $twig = self::twig(['x/greeter/t.html.twig' => '{% for i in 1..9000 %}{% endfor %}{% for i in 1..9000 %}{% endfor %}ok']);
        $renderer = new TwigRenderer($twig);

        for ($i = 0; $i < 10; ++$i) {
            self::assertSame('ok', $renderer->render('x/greeter/t.html.twig'));
        }
    }

    /**
     * @param array<string, string> $extensions
     * @param array<string, string> $plugin
     */
    private static function twig(array $extensions, array $plugin = []): Environment
    {
        $templates = new class ($extensions) implements PackageTemplates {
            /** @param array<string, string> $sources */
            public function __construct(private readonly array $sources) {}

            public function source(string $name): ?string
            {
                return $this->sources[$name] ?? null;
            }

            public function owner(string $name): ?string
            {
                return isset($this->sources[$name]) ? 'greeter' : null;
            }
        };
        $loader = new PackageTemplateLoader($templates);
        $twig = new Environment(new ChainLoader([$loader, new ArrayLoader($plugin)]), ['strict_variables' => true, 'autoescape' => false]);
        $twig->addExtension(new SandboxExtension(StorefrontSandbox::policy(), false, $loader));
        $twig->addExtension(new TemplateBudgetExtension());

        return $twig;
    }
}
