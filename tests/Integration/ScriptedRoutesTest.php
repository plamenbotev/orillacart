<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Assistant\Application\AssistantTools;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Shared\Http\Routing\Tool;
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
          needs: { templates: override, assets: [serve, scripts] }
        }
        route GET "/hello/{name}" { who: public } fn(request) {
          return { hello: request.params.name, q: request.query.x ?? null, path: request.path }
        }
        route POST "/echo" { who: manager } fn(request) {
          return respond.json({ got: request.body, manager: request.user.manager }, 201)
        }
        route GET "/away" { who: public } fn(request) { return respond.redirect("/thanks?from=away", 307) }
        route GET "/nope" { who: public } fn(request) { return respond.error(418, "No tea.") }
        route GET "/mine" { who: account } fn(request) { return { id: request.user.id } }
        route POST "/vote" { who: public } fn(request) { return { voted: true } }
        route POST "/code" { who: public, limit: 2 } fn(request) { return { tried: request.body.code ?? "" } }
        route POST "/hook" { who: public, limit: none } fn(request) { return { got: true } }
        route GET "/listed" { who: public, cache: 60 } fn(request) { return { colour: request.query.colour ?? [], at: time.now(), who: request.user.id } }
        route GET "/page" { who: public } fn(request) { return respond.html("hello.html.twig", { who: "Ada", total: money("12.5"), lines: [{ price: money("3") }] }) }
        route GET "/boom" { who: public } fn(request) { fail refused("Not today.") }
        route GET "/broken" { who: public } fn(request) { let x = 1 / 0 }
        page "/support" { template: "support.html.twig" } fn(request) {
          return { tickets: ["one", "two"], from: request.query.from ?? "nowhere" }
        }
        slot "product_after_details" { template: "note.html.twig" } fn(context) { return { label: "Shelf A" } }
        slot "cart_after" { template: "plain.html.twig" }
        admin_page "/tickets" { title: "Tickets", template: "admin.html.twig" } fn(request) {
          return { open: 3, manager: request.user.manager, state: request.query.state ?? "all" }
        }
        admin_page "/broken" { title: "Broken", template: "admin.html.twig" } fn(request) { fail refused("Not for you.") }
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
            'templates/hello.html.twig' => '<p>Hello {{ who }} {{ ext.pages.extra }} {{ total }} {{ lines[0].price }}</p>',
            'templates/support.html.twig' => '<ul>{% for t in tickets %}<li>{{ t }}</li>{% endfor %}</ul><em>{{ from }}</em>',
            'templates/note.html.twig' => '<span class="note">{{ label }} in {{ slot }}</span>{{ asset("note.css") }}{{ asset("note.js") }}',
            'templates/admin.html.twig' => '<p class="tickets">{{ open }} open, {{ state }}, manager {{ manager ? "yes" : "no" }}</p>{{ asset("admin.js") }}',
            'assets/admin.js' => 'console.log("admin")',
            'templates/plain.html.twig' => '<i>{{ slot }}</i>',
            'assets/note.css' => '.note { color: red }',
            'assets/note.js' => 'console.log("note")',
            'templates/bad.html.twig' => "{{ constant('PHP_VERSION') }}",
            'templates/storefront/_free_shipping.html.twig' => '<b>overridden</b>',
        ]], nonce: true);
        $enabled = $this->request('POST', '/extensions/pages/enable', ['grant' => ['templates', 'assets', 'assets:scripts']], nonce: true);
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
        self::assertSame(Tool::CHANGES, $offered['x_pages_post_echo']->risk, 'a route that changes something and names no risk waits for the owner\'s yes');

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
        self::assertSame(307, $away->get_status(), 'the status the script asked for');
        self::assertSame('/thanks?from=away', $away->get_headers()['Location'] ?? null, 'a path of the site; another host is refused (RespondRedirectTest)');

        $nope = $this->request('GET', '/x/pages/nope');
        self::assertSame([418, 'No tea.'], [$nope->get_status(), $this->data($nope)['message']]);
        $boom = $this->request('GET', '/x/pages/boom');
        self::assertSame([400, 'Not today.'], [$boom->get_status(), $this->data($boom)['message']]);

        $page = $this->request('GET', '/x/pages/page');
        self::assertSame(200, $page->get_status());
        self::assertSame(['html' => '<p>Hello Ada added 12.5000 3.0000</p>'], $this->data($page), 'the template, with what template_data added, as JSON; money as amounts, nested too');

        $this->actAs('administrator');
        $echo = $this->request('POST', '/x/pages/echo', ['a' => 1], nonce: true);
        self::assertSame(201, $echo->get_status(), (string) wp_json_encode($echo->get_data()));
        self::assertSame(['got' => ['a' => 1], 'manager' => true], $this->data($echo));
        $mine = $this->request('GET', '/x/pages/mine');
        self::assertSame(get_current_user_id(), $this->data($mine)['id']);
    }

    public function testOnlyAVisitorsFormsAreCountedEachExtensionsApartAndARoutesOwnAlone(): void
    {
        $limits = static fn(array $limits): array => ['extension_public' => [2, 600]] + $limits;
        add_filter('orillacart_rate_limits', $limits);
        $address = $_SERVER['REMOTE_ADDR'] ?? null;
        try {
            $this->request('POST', '/extensions', ['id' => 'second', 'name' => 'Second', 'files' => [
                'main.ocs' => "extension \"second\" { name: \"Second\" }\nroute POST \"/vote\" { who: public } fn(request) { return { ok: true } }\n",
            ]], nonce: true);
            self::assertSame(200, $this->request('POST', '/extensions/second/enable', ['grant' => []], nonce: true)->get_status());
            $GLOBALS['wp_rest_server'] = null;

            // A manager calls as often as the work needs: signed-in callers are not counted.
            for ($i = 0; $i < 5; ++$i) {
                self::assertSame(201, $this->request('POST', '/x/pages/echo', ['n' => $i], nonce: true)->get_status());
            }

            // A visitor's forms are, by address, and each extension's apart; reads are not.
            wp_set_current_user(0);
            $_SERVER['REMOTE_ADDR'] = self::someAddress();
            for ($i = 0; $i < 5; ++$i) {
                self::assertSame(200, $this->request('GET', '/x/pages/hello/v' . $i)->get_status(), 'a read is never counted');
            }
            foreach ([1, 2] as $n) {
                self::assertSame(200, $this->request('POST', '/x/pages/vote', ['n' => $n], nonce: true)->get_status());
            }
            $away = $this->request('POST', '/x/pages/vote', ['n' => 3], nonce: true);
            self::assertSame(429, $away->get_status(), (string) wp_json_encode($away->get_data()));
            $data = $this->data($away);
            self::assertSame('orillacart_too_many_requests', $data['code'] ?? null);
            $details = $data['data'] ?? null;
            self::assertIsArray($details);
            $wait = $details['retry_after'] ?? null;
            self::assertIsInt($wait);
            self::assertGreaterThan(0, $wait);
            self::assertSame((string) $wait, self::text($away->get_headers()['Retry-After'] ?? null), 'the header says it too');
            self::assertSame(200, $this->request('POST', '/x/second/vote', nonce: true)->get_status(), 'another extension counts its own');
            self::assertSame(200, $this->request('POST', '/x/pages/hook', nonce: true)->get_status(), 'limit: none');

            // A route's own limit counts it alone, whatever the extension's.
            $_SERVER['REMOTE_ADDR'] = self::someAddress();
            foreach (['a', 'b'] as $code) {
                self::assertSame(200, $this->request('POST', '/x/pages/code', ['code' => $code], nonce: true)->get_status());
            }
            self::assertSame(429, $this->request('POST', '/x/pages/code', ['code' => 'c'], nonce: true)->get_status());
            self::assertSame(200, $this->request('POST', '/x/pages/vote', nonce: true)->get_status(), 'the route\'s own count is not the extension\'s');
        } finally {
            remove_filter('orillacart_rate_limits', $limits);
            $_SERVER['REMOTE_ADDR'] = $address;
            $this->actAs('administrator');
            $this->request('DELETE', '/extensions/second', nonce: true);
        }
    }

    public function testAVisitorsReadIsAnsweredFromTheRoutesCache(): void
    {
        wp_set_current_user(0);
        $colour = 'c' . wp_generate_password(8, false);
        $first = $this->request('GET', '/x/pages/listed?colour[]=' . $colour . '&colour[]=red');
        self::assertSame(200, $first->get_status(), (string) wp_json_encode($first->get_data()));
        self::assertSame('miss', $first->get_headers()['X-OrillaCart-Cache'] ?? null);
        $again = $this->request('GET', '/x/pages/listed?colour[]=red&colour[]=' . $colour);
        self::assertSame('hit', $again->get_headers()['X-OrillaCart-Cache'] ?? null, 'the same choices in another order');
        self::assertSame($this->data($first), $this->data($again));

        $other = $this->request('GET', '/x/pages/listed?colour[]=blue&colour[]=' . $colour);
        self::assertSame('miss', $other->get_headers()['X-OrillaCart-Cache'] ?? null, 'other choices are another answer');

        $this->actAs('administrator');
        $signedIn = $this->request('GET', '/x/pages/listed?colour[]=' . $colour . '&colour[]=red');
        self::assertArrayNotHasKey('X-OrillaCart-Cache', $signedIn->get_headers(), 'a signed-in caller\'s answer may be theirs alone');
        self::assertSame(get_current_user_id(), $this->data($signedIn)['who'] ?? null);
    }

    private static function someAddress(): string
    {
        return '2001:db8:' . dechex(random_int(1, 0xFFFF)) . ':' . dechex(random_int(1, 0xFFFF)) . '::7';
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

    /** Design 60: an extension's screens in the Shop menu, before System, rendered from its template. */
    public function testAnExtensionsAdminPagesAreScreensOfTheShopMenu(): void
    {
        global $submenu;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        do_action('admin_menu');

        self::assertIsArray($submenu);
        $shop = $submenu['orillacart'] ?? [];
        self::assertIsArray($shop);
        $items = array_values(array_filter($shop, static fn(mixed $item): bool => \is_array($item) && ($item[4] ?? '') !== 'orillacart-menu-hidden'));
        $titles = array_column($items, 0);
        self::assertSame(['Tickets', 'Broken', 'System'], \array_slice($titles, -3), 'after the shop\'s groups, before System');
        $slugs = array_column($items, 2);
        self::assertContains('orillacart-x-pages-tickets', $slugs);

        $_GET['state'] = 'open';
        ob_start();
        do_action('shop_page_orillacart-x-pages-tickets');
        $printed = (string) ob_get_clean();
        unset($_GET['state']);
        // The screen, from the template with the handler's data, in a frame of its own (design 73). Its script is
        // printed once in the process, which another test of the run may have rendered first.
        self::assertStringStartsWith('<div class="wrap orillacart-extension-page" data-extension="pages"><h1>Tickets</h1><iframe class="orillacart-extension-frame" data-orillacart-frame data-extension="pages" sandbox="allow-scripts" ', $printed);
        self::assertSame(1, preg_match('#srcdoc="([^"]*)"#', $printed, $match));
        $document = html_entity_decode($match[1], \ENT_QUOTES | \ENT_HTML5);
        self::assertStringContainsString('<body class="wp-core-ui"><p class="tickets">3 open, open, manager yes</p>', $document);
        self::assertMatchesRegularExpression('#<meta http-equiv="Content-Security-Policy" content="default-src &\\#039;none&\\#039;; script-src [^ ]+/build/admin/extension-bridge\\.js [^ ]+/orillacart/extensions/pages/; #', $document, 'only the bridge and the extension\'s files run');
        self::assertStringContainsString('connect-src &#039;none&#039;', $document);
        self::assertLessThanOrEqual(1, preg_match_all('#<script src="[^"]+/orillacart/extensions/pages/admin-[0-9a-f]{12}\.js" defer></script>#', $document), 'printed once at most');
        self::assertStringNotContainsString('sandbox="allow-scripts allow-same-origin', $printed);

        ob_start();
        do_action('shop_page_orillacart-x-pages-broken');
        self::assertStringContainsString('<div class="notice notice-warning inline"><p>Not for you.</p></div>', (string) ob_get_clean());
    }

    /** PLAN 373: a view rendered over REST, for whoever writes the extension without a browser. */
    public function testAViewIsPreviewedAsTheShopWouldShowIt(): void
    {
        $page = $this->data($this->request('POST', '/extensions/pages/preview', ['kind' => 'page', 'name' => '/support', 'query' => ['from' => 'the console']], nonce: true));
        self::assertSame('<ul><li>one</li><li>two</li></ul><em>the console</em>', $page['html']);
        self::assertSame(['tickets' => ['one', 'two'], 'from' => 'the console'], $page['data']);

        $admin = $this->data($this->request('POST', '/extensions/pages/preview', ['kind' => 'admin_page', 'name' => '/tickets', 'query' => ['state' => 'open']], nonce: true));
        self::assertStringStartsWith('<p class="tickets">3 open, open, manager yes</p>', self::text($admin['html']));

        $route = $this->data($this->request('POST', '/extensions/pages/preview', ['kind' => 'route', 'name' => 'GET /page'], nonce: true));
        self::assertSame('<p>Hello Ada added 12.5000 3.0000</p>', $route['html'], 'a route answering HTML, rendered');
        $json = $this->data($this->request('POST', '/extensions/pages/preview', ['kind' => 'route', 'name' => 'POST /echo', 'body' => ['a' => 1]], nonce: true));
        self::assertSame(['html' => '', 'data' => ['got' => ['a' => 1], 'manager' => true]], $json);

        $slot = $this->data($this->request('POST', '/extensions/pages/preview', ['kind' => 'slot', 'name' => 'cart_after'], nonce: true));
        self::assertSame(['html' => '<i>cart_after</i>', 'data' => null], $slot);

        $refused = $this->request('POST', '/extensions/pages/preview', ['kind' => 'admin_page', 'name' => '/broken'], nonce: true);
        self::assertSame([422, 'The handler refused: Not for you.'], [$refused->get_status(), $this->data($refused)['message']]);
        $missing = $this->request('POST', '/extensions/pages/preview', ['kind' => 'page', 'name' => '/nowhere'], nonce: true);
        self::assertSame(422, $missing->get_status());
    }

    public function testASlotTemplatePrintsInTheStorefrontsSlot(): void
    {
        ob_start();
        do_action('orillacart_product_after_details', ['id' => 1]);
        $printed = (string) ob_get_clean();

        // Design 59: its stylesheet and script, served from the uploads folder under a name that carries the content's
        // hash, printed once in the process (one request is one page; another test of the run may have printed them).
        $uploads = wp_upload_dir(null, false);
        self::assertStringStartsWith('<span class="note">Shelf A in product_after_details</span>', $printed);
        $assets = self::kernel()->container()->get(\OrillaCart\Packages\Application\ExtensionAssets::class);
        self::assertInstanceOf(\OrillaCart\Packages\Application\ExtensionAssets::class, $assets);
        self::assertSame($uploads['baseurl'] . '/orillacart/extensions/pages/', substr((string) $assets->url('pages', 'note.css'), 0, \strlen($uploads['baseurl'] . '/orillacart/extensions/pages/')));
        $css = glob($uploads['basedir'] . '/orillacart/extensions/pages/note-*.css');
        self::assertIsArray($css);
        self::assertCount(1, $css, 'published once, under a hashed name');
        self::assertMatchesRegularExpression('#/note-[0-9a-f]{12}\.css$#', $css[0]);
        self::assertSame('.note { color: red }', file_get_contents($css[0]));
        if (str_contains($printed, '<link')) {
            self::assertStringContainsString('<link rel="stylesheet" href="' . $uploads['baseurl'] . '/orillacart/extensions/pages/' . basename($css[0]) . '">', $printed);
        }

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
        self::assertSame('<p>Hello Ada added 1 2</p>', $renderer->render('x/pages/hello.html.twig', ['who' => 'Ada', 'total' => '1', 'lines' => [['price' => '2']]]));

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

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

}
