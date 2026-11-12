<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\Twig\ScriptFreeHtml;
use OrillaCart\Shared\View\TemplateRenderer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Design 73: what an extension's template prints reaches the browser without what would run there, however the
 * template put it together; what the shop prints around it is left as it is.
 */
final class ScriptFreeTemplatesTest extends RestTestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "clean" {
          name: "Clean"
          needs: { templates: override }
        }
        OCS;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        $this->request('DELETE', '/extensions/clean', nonce: true);
        $installed = $this->request('POST', '/extensions', ['id' => 'clean', 'name' => 'Clean', 'files' => [
            'main.ocs' => self::SCRIPT,
            'templates/raw.html.twig' => '<p>{{ html|raw }}</p>',
            'templates/link.html.twig' => '<a href="{{ url }}">go</a>',
            'templates/built.html.twig' => '<scr{{ s }}>alert(1)</scr{{ s }}><p>after</p>',
            'templates/data.html.twig' => '<div data-x>{{ json_data(points) }}</div>',
            'templates/layout.html.twig' => '<main>{% block main %}{% endblock %}</main>',
            'templates/page.html.twig' => '{% extends "x/clean/layout.html.twig" %}{% block main %}<a href="{{ url }}">go</a>{% endblock %}',
            'templates/open.html.twig' => '<p>{{ html|raw }}',
            'templates/storefront/_free_shipping.html.twig' => '<a href="{{ url ?? "/" }}">free</a>',
        ]], nonce: true);
        self::assertSame(201, $installed->get_status(), (string) wp_json_encode($installed->get_data()));
        $enabled = $this->request('POST', '/extensions/clean/enable', ['grant' => ['templates']], nonce: true);
        self::assertSame(200, $enabled->get_status(), (string) wp_json_encode($enabled->get_data()));
    }

    public function testWhatATemplatePrintsIsCleanedAsPrinted(): void
    {
        $renderer = $this->renderer();

        self::assertSame('<p><img src="x" ></p>', $renderer->render('x/clean/raw.html.twig', ['html' => '<img src="x" onerror="alert(1)">']), 'raw HTML from the handler loses its handlers');
        self::assertSame('<a >go</a>', $renderer->render('x/clean/link.html.twig', ['url' => 'javascript:alert(1)']), 'escaping keeps a javascript: URL working; cleaning takes it out');
        self::assertSame('<a href="/cart">go</a>', $renderer->render('x/clean/link.html.twig', ['url' => '/cart']));
        self::assertSame('<script type="text/plain">alert(1)</script><p>after</p>', $renderer->render('x/clean/built.html.twig', ['s' => 'ipt']), 'a tag made of pieces is one once printed');
        self::assertSame('<main><a >go</a></main>', $renderer->render('x/clean/page.html.twig', ['url' => ' JaVa script:alert(1)']), 'a block the layout prints is cleaned too');
        self::assertSame('&lt;p&gt;&lt;a href=&quot;x', $renderer->render('x/clean/open.html.twig', ['html' => '<a href="x']), 'a piece ending inside a tag would run into what follows: escaped');
        self::assertSame('<a >free</a>', $renderer->render('storefront/_free_shipping.html.twig', ['url' => 'javascript:alert(1)']), 'an override of the shop\'s template is the extension\'s');
    }

    public function testDataForAScriptIsPrintedAsDataTheBrowserNeverRuns(): void
    {
        self::assertSame('<div data-x><script type="application/json">{"n":3,"s":"\u003C\/script\u003E"}</script></div>', $this->renderer()->render('x/clean/data.html.twig', ['points' => ['n' => 3, 's' => '</script>']]));
    }

    public function testTheCheckSaysWhatWouldNotRun(): void
    {
        $checked = $this->data($this->request('POST', '/script/check', ['files' => [
            'templates/x.html.twig' => "<p>a</p>\n<script>alert(1)</script>\n<button onclick=\"go()\">b</button><a href=\"javascript:go()\">c</a>\n<script type=\"application/json\">{}</script>",
        ], 'id' => 'draft'], nonce: true));
        self::assertIsArray($checked);
        $findings = array_map(static fn(mixed $f): string => \is_array($f) && \is_int($f['line'] ?? null) && \is_string($f['message'] ?? null) ? $f['line'] . ' ' . $f['message'] : '', \is_array($checked['findings'] ?? null) ? $checked['findings'] : []);
        self::assertSame([
            '2 An inline script does not run in an extension\'s template: put it in a file under assets/ and print it with asset() (assets: scripts), with what it needs as json_data(…).',
            '3 An "onclick" attribute does not run in an extension\'s template: listen for the event in a script under assets/ (addEventListener).',
            '3 A javascript: address does not run in an extension\'s template: listen for the click in a script under assets/.',
        ], $findings, 'data is no script');
    }

    /** @return iterable<string, array{string, string}> */
    public static function pieces(): iterable
    {
        yield 'handlers' => ['<button onclick="x()" ONMOUSEOVER=y>b</button>', '<button  >b</button>'];
        yield 'a script' => ['<script src="https://evil.example/x.js"></script>', '<script type="text/plain" ></script>'];
        yield 'a module' => ['<script type="module">x()</script>', '<script type="text/plain">x()</script>'];
        yield 'data stays' => ['<script type="application/ld+json">{"a":1}</script>', '<script type="application/ld+json">{"a":1}</script>'];
        yield 'an address with tabs' => ["<a href=\"jav\tascript:x()\">a</a>", '<a >a</a>'];
        yield 'an entity in an address' => ['<a href="javascript&colon;x()">a</a>', '<a >a</a>'];
        yield 'a data document' => ['<iframe src="data:text/html,<script>x()</script>"></iframe>', '<iframe ></iframe>'];
        yield 'an image as data' => ['<img src="data:image/png;base64,AAAA">', '<img src="data:image/png;base64,AAAA">'];
        yield 'srcdoc' => ['<iframe srcdoc="&lt;script&gt;x()&lt;/script&gt;"></iframe>', '<iframe ></iframe>'];
        yield 'base' => ['<base href="https://evil.example/">', '<base >'];
        yield 'refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">', '<meta  >'];
        yield 'object' => ['<object data="x.swf"></object><embed src="x.swf">', '<object ></object><embed >'];
        yield 'svg' => ['<svg><a xlink:href="javascript:x()"><text>t</text></a><animate attributeName="href" to="javascript:x()"/></svg>', '<svg><a ><text>t</text></a><animate  /></svg>'];
        yield 'a style in svg' => ['<svg><style><img src=x onerror=x()></style></svg>', '&lt;svg&gt;&lt;style&gt;&lt;img src=x onerror=x()&gt;&lt;/style&gt;&lt;/svg&gt;'];
        yield 'noscript' => ['<noscript><p title="</noscript><img src=x onerror=x()>"></p></noscript>', '&lt;noscript&gt;&lt;p title=&quot;&lt;/noscript&gt;&lt;img src=x onerror=x()&gt;&quot;&gt;&lt;/p&gt;&lt;/noscript&gt;'];
        yield 'an open comment' => ['<p>a</p><!-- ', '&lt;p&gt;a&lt;/p&gt;&lt;!-- '];
        yield 'plain HTML' => ['<form action="/cart" method="post"><input name="q" value="a &amp; b"><a href="https://example.com/?a=1&amp;b=2" target="_blank">x</a></form>', '<form action="/cart" method="post"><input name="q" value="a &amp; b"><a href="https://example.com/?a=1&amp;b=2" target="_blank">x</a></form>'];
        yield 'text' => ['Just 5 < 6', 'Just 5 < 6'];
    }

    #[DataProvider('pieces')]
    public function testAPieceIsCleaned(string $html, string $clean): void
    {
        self::assertSame($clean, ScriptFreeHtml::clean($html));
    }

    private function renderer(): TemplateRenderer
    {
        $renderer = self::kernel()->container()->get(TemplateRenderer::class);
        \assert($renderer instanceof TemplateRenderer);

        return $renderer;
    }
}
