<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Assistant\Application\AssistantTools;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Shared\View\TemplateRenderer;
use Twig\Sandbox\SecurityError;

/**
 * Design 56: an extension's routes through the shop's REST pipeline, its pages on a block, its templates in
 * slots, its data added to a template, and its override of a shop template, in the sandbox.
 */
final class ScriptedRoutesTest extends RestTestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "pages" {
          name: "Pages"
          needs: { templates: override, assets: serve }
        }
        route GET "/hello/{name}" { who: public } fn(request) {
          return { hello: request.params.name, q: request.query.x ?? null, path: request.path }
        }
        route POST "/echo" { who: manager } fn(request) {
          return respond.json({ got: request.body, manager: request.user.manager }, 201)
        }
        route GET "/away" { who: public } fn(request) { return respond.redirect("https://example.com/") }
        route GET "/nope" { who: public } fn(request) { return respond.error(418, "No tea.") }
        route GET "/mine" { who: account } fn(request) { return { id: request.user.id } }
        route GET "/page" { who: public } fn(request) { return respond.html("hello.html.twig", { who: "Ada" }) }
        route GET "/boom" { who: public } fn(request) { fail refused("Not today.") }
        route GET "/broken" { who: public } fn(request) { let x = 1 / 0 }
        page "/support" { template: "support.html.twig" } fn(request) {
          return { tickets: ["one", "two"], from: request.query.from ?? "nowhere" }
        }
        slot "product_after_details" { template: "note.html.twig" } fn(context) { return { label: "Shelf A" } }
        template_data "x/pages/hello.html.twig" fn(context) { return { extra: "added" } }
        OCS;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        foreach ($this->rows($this->data($this->request('GET', '/extensions'))['extensions']) as $existing) {
            $this->request('DELETE', '/extensions/' . (\is_string($existing['id'] ?? null) ? $existing['id'] : ''), nonce: true);
        }
        $this->request('POST', '/extensions', ['id' => 'pages', 'name' => 'Pages', 'files' => [
            'main.ocs' => self::SCRIPT,
            'templates/hello.html.twig' => '<p>Hello {{ who }} {{ ext.pages.extra }}</p>',
            'templates/support.html.twig' => '<ul>{% for t in tickets %}<li>{{ t }}</li>{% endfor %}</ul><em>{{ from }}</em>',
            'templates/note.html.twig' => '<span class="note">{{ label }} in {{ slot }}</span>{{ asset("note.css") }}{{ asset("note.js") }}',
            'assets/note.css' => '.note { color: red }',
            'assets/note.js' => 'console.log("note")',
            'templates/bad.html.twig' => "{{ constant('PHP_VERSION') }}",
            'templates/storefront/_free_shipping.html.twig' => '<b>overridden</b>',
        ]], nonce: true);
        $enabled = $this->request('POST', '/extensions/pages/enable', ['grant' => ['templates', 'assets']], nonce: true);
        self::assertSame(200, $enabled->get_status(), (string) wp_json_encode($enabled->get_data()));
        // The REST routes register once per server: a new server reads the extension's routes.
        $GLOBALS['wp_rest_server'] = null;
    }

    public function testManagerRoutesAreTheAssistantsTools(): void
    {
        $tools = self::kernel()->container()->get(AssistantTools::class);
        \assert($tools instanceof AssistantTools);
        $caller = new Caller(get_current_user_id(), null, false);
        $offered = [];
        foreach ($tools->offered($caller) as $tool) {
            $offered[$tool->name] = $tool;
        }
        self::assertArrayHasKey('x_pages_post_echo', $offered, 'the manager route; public and account routes make no tool');
        self::assertArrayNotHasKey('x_pages_get_hello_name', $offered);
        self::assertSame(['Extensions', false, true, 'POST /echo of the extension "Pages".'], [$offered['x_pages_post_echo']->area, $offered['x_pages_post_echo']->readOnly, $offered['x_pages_post_echo']->addOn, $offered['x_pages_post_echo']->description]);

        $outcome = $tools->call('x_pages_post_echo', ['body' => ['a' => 1]], $caller);
        self::assertNotNull($outcome);
        self::assertSame(['got' => ['a' => 1], 'manager' => true], $outcome->data);
        $readOnly = $tools->call('x_pages_post_echo', ['body' => []], new Caller(get_current_user_id(), null, true));
        self::assertTrue($readOnly === null || $readOnly->isError(), 'a read-only key cannot change the shop through an extension');
    }

    public function testRoutesAnswerThroughTheShopsPipeline(): void
    {
        wp_set_current_user(0);
        $hello = $this->request('GET', '/x/pages/hello/ada?x=1');
        self::assertSame(200, $hello->get_status(), (string) wp_json_encode($hello->get_data()));
        self::assertSame(['hello' => 'ada', 'q' => '1', 'path' => 'pages/hello/{name}'], $this->data($hello));

        self::assertSame(401, $this->request('GET', '/x/pages/mine')->get_status(), 'an account route asks a guest to log in');
        self::assertSame(401, $this->request('POST', '/x/pages/echo', ['a' => 1])->get_status(), 'a manager route refuses a guest');

        $away = $this->request('GET', '/x/pages/away');
        self::assertSame(303, $away->get_status(), 'as the shop redirects');
        self::assertSame('https://example.com/', $away->get_headers()['Location'] ?? null);

        $nope = $this->request('GET', '/x/pages/nope');
        self::assertSame([418, 'No tea.'], [$nope->get_status(), $this->data($nope)['message']]);
        $boom = $this->request('GET', '/x/pages/boom');
        self::assertSame([400, 'Not today.'], [$boom->get_status(), $this->data($boom)['message']]);

        $page = $this->request('GET', '/x/pages/page');
        self::assertSame(200, $page->get_status());
        self::assertSame(['html' => '<p>Hello Ada added</p>'], $this->data($page), 'the template, with what template_data added, as JSON');

        $this->actAs('administrator');
        $echo = $this->request('POST', '/x/pages/echo', ['a' => 1], nonce: true);
        self::assertSame(201, $echo->get_status(), (string) wp_json_encode($echo->get_data()));
        self::assertSame(['got' => ['a' => 1], 'manager' => true], $this->data($echo));
        $mine = $this->request('GET', '/x/pages/mine');
        self::assertSame(get_current_user_id(), $this->data($mine)['id']);
    }

    public function testAStoppedExtensionsRoutesSaySo(): void
    {
        wp_set_current_user(0);
        $this->expectOutputRegex('/WARNING: The extension "pages" failed: Division by zero.*ERROR: The extension "pages" failed 10 times/s');
        for ($i = 0; $i < Package::FAILURES_TO_STOP; ++$i) {
            $broken = $this->request('GET', '/x/pages/broken');
            self::assertSame(500, $broken->get_status(), 'a broken handler is a failure of the extension');
        }

        $answer = $this->request('GET', '/x/pages/hello/ada');
        self::assertSame(503, $answer->get_status(), (string) wp_json_encode($answer->get_data()));
        $message = $this->data($answer)['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('stopped', $message);
        $packages = self::kernel()->container()->get(PackageService::class);
        \assert($packages instanceof PackageService);
        self::assertSame('stopped', $packages->package('pages')->state);
    }

    public function testAPageRendersOnItsBlockWithTheQueryAsTheRequest(): void
    {
        $_GET['from'] = 'the block';
        try {
            $html = do_blocks('<!-- wp:orillacart/extension-page {"extension":"pages","path":"/support"} /-->');
        } finally {
            unset($_GET['from']);
        }

        self::assertStringContainsString('<li>one</li><li>two</li>', $html);
        self::assertStringContainsString('<em>the block</em>', $html);
        self::assertSame('', trim(wp_strip_all_tags(do_blocks('<!-- wp:orillacart/extension-page {"extension":"pages","path":"/missing"} /-->'))), 'no such page: nothing');
    }

    public function testASlotTemplatePrintsInTheStorefrontsSlot(): void
    {
        ob_start();
        do_action('orillacart_product_after_details', ['id' => 1]);
        $printed = (string) ob_get_clean();

        // Design 59: its stylesheet and script, served from the uploads folder under a name that carries the content's hash.
        $uploads = wp_upload_dir(null, false);
        self::assertMatchesRegularExpression('#^<span class="note">Shelf A in product_after_details</span><link rel="stylesheet" href="' . preg_quote($uploads['baseurl'], '#') . '/orillacart/extensions/pages/note-[0-9a-f]{12}\.css"><script src="' . preg_quote($uploads['baseurl'], '#') . '/orillacart/extensions/pages/note-[0-9a-f]{12}\.js" defer></script>$#', $printed);
        preg_match('#href="[^"]*/(note-[0-9a-f]{12}\.css)"#', $printed, $match);
        self::assertFileExists($uploads['basedir'] . '/orillacart/extensions/pages/' . ($match[1] ?? ''));
        self::assertSame('.note { color: red }', file_get_contents($uploads['basedir'] . '/orillacart/extensions/pages/' . ($match[1] ?? '')));

        ob_start();
        do_action('orillacart_product_after_details', ['id' => 2]);
        self::assertSame('<span class="note">Shelf A in product_after_details</span>', (string) ob_get_clean(), 'printed once per page');

        $checked = $this->data($this->request('POST', '/script/check', ['files' => ['templates/x.html.twig' => '{{ asset("nope.js") }}', 'assets/here.js' => '1'], 'id' => 'draft'], nonce: true));
        self::assertSame([['file' => 'templates/x.html.twig', 'line' => 1, 'column' => 1, 'message' => 'asset("nope.js") names a file the extension does not have: add assets/nope.js.']], $checked['findings']);
        $this->request('DELETE', '/extensions/pages', nonce: true);
        self::assertDirectoryDoesNotExist($uploads['basedir'] . '/orillacart/extensions/pages', 'uninstalled, files and all');
    }

    public function testAnExtensionOverridesAShopTemplateInTheSandbox(): void
    {
        $renderer = self::kernel()->container()->get(TemplateRenderer::class);
        \assert($renderer instanceof TemplateRenderer);

        self::assertSame('<b>overridden</b>', $renderer->render('storefront/_free_shipping.html.twig', []), 'the extension granted templates wins over the plugin\'s');
        self::assertSame('<p>Hello Ada added</p>', $renderer->render('x/pages/hello.html.twig', ['who' => 'Ada']));

        $this->expectException(SecurityError::class);
        $renderer->render('x/pages/bad.html.twig', ['who' => 'Ada']);
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        $rows = [];
        foreach (\is_array($value) ? $value : [] as $row) {
            $assoc = [];
            foreach (\is_array($row) ? $row : [] as $key => $item) {
                $assoc[(string) $key] = $item;
            }
            $rows[] = $assoc;
        }

        return $rows;
    }
}
