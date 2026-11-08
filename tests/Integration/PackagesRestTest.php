<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * Design 56: an extension in the script language installed, written, checked, enabled with granted permissions,
 * run from the console, given settings, exported, disabled and uninstalled, over the REST API the admin uses.
 */
final class PackagesRestTest extends RestTestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "greeter" {
          name: "Greeter"
          version: "1.0.0"
          needs: { }
        }

        settings {
          greeting: text { label: "Greeting", required: true }
          token: secret { label: "Token" }
          loud: boolean { label: "Loud" }
        }

        fn greet(name) {
          return "Hi, " ~ name ~ " at " ~ time.today()
        }
        OCS;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        foreach ($this->rows($this->data($this->request('GET', '/extensions'))['extensions']) as $existing) {
            $this->request('DELETE', '/extensions/' . self::string($existing['id']), nonce: true);
        }
    }

    public function testVersionsAreKeptOnEverySaveAndRestoredRunsAreLoggedAndOverridesKnowTheirOriginal(): void
    {
        $this->request('POST', '/extensions', ['id' => 'kept', 'name' => 'Kept', 'files' => ['main.ocs' => "extension \"kept\" { name: \"Kept\" }\nfn one() { return 1 }\n"]], nonce: true);
        $this->request('PUT', '/extensions/kept/files/main.ocs', ['content' => "extension \"kept\" { name: \"Kept\" }\nfn one() { return 2 }\n"], nonce: true);
        $this->request('PUT', '/extensions/kept/files/main.ocs', ['content' => "extension \"kept\" { name: \"Kept\" }\nfn one() { return 2 }\n"], nonce: true);
        $this->request('PUT', '/extensions/kept/files/' . rawurlencode('templates/storefront/_free_shipping.html.twig'), ['content' => '<b>mine</b>'], nonce: true);

        $versions = $this->rows($this->data($this->request('GET', '/extensions/kept/versions'))['versions']);
        self::assertSame([[3, 'Added templates/storefront/_free_shipping.html.twig', true], [2, 'Saved main.ocs', false], [1, 'Installed', false]], array_map(static fn(array $v): array => [$v['number'], $v['note'], $v['current']], $versions), 'saving the same content again keeps no version');

        // The override knows the shop\'s template it replaces, is not stale, and the original is there for the diff.
        $files = $this->rows($this->data($this->request('GET', '/extensions/kept'))['files']);
        $override = array_values(array_filter($files, static fn(array $f): bool => $f['path'] === 'templates/storefront/_free_shipping.html.twig'))[0];
        self::assertSame(['storefront/_free_shipping.html.twig', false], [$override['overrides'], $override['stale']]);
        self::assertNull($files[0]['overrides'], 'a script overrides nothing');
        $original = $this->data($this->request('GET', '/extensions/kept/files/' . rawurlencode('templates/storefront/_free_shipping.html.twig') . '/original'))['original'];
        self::assertIsString($original);
        self::assertStringContainsString('{%', $original, 'the plugin\'s own template');
        self::assertNull($this->data($this->request('GET', '/extensions/kept/files/main.ocs/original'))['original']);

        // Restoring version 1 puts the files back and is the newest version; the console run is logged.
        $restored = $this->data($this->request('POST', '/extensions/kept/versions/1/restore', [], nonce: true));
        self::assertSame(['main.ocs'], array_column($this->rows($restored['files']), 'path'));
        $this->request('POST', '/extensions/kept/enable', ['grant' => []], nonce: true);
        self::assertSame(1, $this->data($this->request('POST', '/extensions/kept/run', ['function' => 'one'], nonce: true))['result']);
        $this->request('POST', '/extensions/kept/run', ['function' => 'one', 'arguments' => [1]], nonce: true);
        $versions = $this->rows($this->data($this->request('GET', '/extensions/kept/versions'))['versions']);
        self::assertSame([4, 'Restored version 1', true], [$versions[0]['number'], $versions[0]['note'], $versions[0]['current']]);
        self::assertSame(422, $this->request('POST', '/extensions/kept/versions/9/restore', [], nonce: true)->get_status());

        $runs = $this->rows($this->data($this->request('GET', '/extensions/kept/runs'))['runs']);
        self::assertSame([['console: one', 'error', 'one expects 0 arguments, 1 given'], ['console: one', 'ok', null]], array_map(static fn(array $r): array => [$r['what'], $r['outcome'], $r['message']], $runs));
        self::assertArrayHasKey('steps', self::assoc($runs[1]['used']));
        $this->request('DELETE', '/extensions/kept', nonce: true);
    }

    public function testTheExtensionsOwnTestsRunOverSavedOrSentFilesAndLeaveNoRecords(): void
    {
        $script = <<<'OCS'
            extension "tested" { name: "Tested", needs: { http: ["api.example"] } }
            object "note" { fields: { text: text }, index: [text] }

            fn keep(text) { return objects.note.create({ text: text }) }
            fn quote() { return http.get("https://api.example/quote").json.price }

            test "keeps a note" fn() {
              keep("hello")
              assert objects.note.count({}) == 1, "one note, got " ~ objects.note.count({})
            }
            test "quotes" { http: { "GET https://api.example/quote": { json: { price: 5 } } } } fn() {
              assert quote() == 5
            }
            OCS;
        $installed = $this->request('POST', '/extensions', ['id' => 'tested', 'name' => 'Tested', 'files' => ['main.ocs' => $script]], nonce: true);
        self::assertSame(201, $installed->get_status(), (string) wp_json_encode($installed->get_data()));

        // Saved files, the extension still a draft: the tests run with what the needs ask for, and the note is rolled back.
        $answer = $this->request('POST', '/extensions/tested/test', [], nonce: true);
        self::assertSame(200, $answer->get_status(), (string) wp_json_encode($answer->get_data()));
        $tests = $this->rows($this->data($answer)['tests']);
        self::assertSame([['keeps a note', true, null], ['quotes', true, null]], array_map(static fn(array $t): array => [$t['name'], $t['ok'], $t['message']], $tests));
        self::assertSame([['method' => 'GET', 'url' => 'https://api.example/quote', 'status' => 200]], $tests[1]['http']);
        $this->request('POST', '/extensions/tested/enable', ['grant' => ['http']], nonce: true);
        self::assertCount(0, (array) $this->data($this->request('GET', '/extensions/tested/data'))['objects'], 'the test\'s note left no trace');
        $second = $this->rows($this->data($this->request('POST', '/extensions/tested/test', [], nonce: true))['tests']);
        self::assertTrue($second[0]['ok'], 'run again: still one note, since the first run was rolled back');

        // Files sent with the request stand in for the saved ones: an editor tests what is on screen.
        $edited = str_replace('assert quote() == 5', 'assert quote() == 6, "price " ~ quote()', $script);
        $tests = $this->rows($this->data($this->request('POST', '/extensions/tested/test', ['files' => ['main.ocs' => $edited]], nonce: true))['tests']);
        self::assertSame([false, 'price 5', 'main.ocs', 12], [$tests[1]['ok'], $tests[1]['message'], $tests[1]['file'], $tests[1]['line']]);

        $broken = $this->request('POST', '/extensions/tested/test', ['files' => ['main.ocs' => "fn f() { return nothing() }\n"]], nonce: true);
        self::assertSame(422, $broken->get_status(), 'what does not compile is not run');
        $this->request('DELETE', '/extensions/tested', nonce: true);
    }

    public function testAnExtensionIsInstalledCheckedEnabledRunAndUninstalled(): void
    {
        $installed = $this->request('POST', '/extensions', ['id' => 'greeter', 'name' => 'Greeter draft', 'files' => ['main.ocs' => self::SCRIPT]], nonce: true);
        self::assertSame(201, $installed->get_status(), (string) wp_json_encode($installed->get_data()));
        $extension = $this->data($installed);
        self::assertSame(['greeter', 'Greeter', '1.0.0', 'draft', []], [$extension['id'], $extension['name'], $extension['version'], $extension['state'], $extension['findings']], 'the extension block names and versions it');
        self::assertSame(['greeting', 'token', 'loud'], array_column($this->rows($extension['settings']), 'key'));

        // A broken file: the check says where, with the file's name, and enabling is refused.
        $broken = $this->data($this->request('PUT', '/extensions/greeter/files/' . rawurlencode('extra.ocs'), ['content' => "fn oops() {\n  return nothing()\n}\n"], nonce: true));
        self::assertSame(['extra.ocs: Unknown name "nothing" (line 2, column 10)'], $broken['findings']);
        self::assertSame(422, $this->request('POST', '/extensions/greeter/enable', ['grant' => []], nonce: true)->get_status());
        $this->request('DELETE', '/extensions/greeter/files/' . rawurlencode('extra.ocs'), nonce: true);

        $enabled = $this->data($this->request('POST', '/extensions/greeter/enable', ['grant' => []], nonce: true));
        self::assertSame(['enabled', false, []], [$enabled['state'], $enabled['stale'], $enabled['granted']]);

        $run = $this->data($this->request('POST', '/extensions/greeter/run', ['function' => 'greet', 'arguments' => ['Ada']], nonce: true));
        $short = $this->request('POST', '/extensions/greeter/run', ['function' => 'greet', 'arguments' => []], nonce: true);
        self::assertSame(200, $short->get_status(), 'too few arguments is the script\'s failure, not the host\'s');
        $failure = $this->data($short)['failure'];
        self::assertSame('greet expects 1 argument, 0 given', \is_array($failure) ? $failure['message'] : null);
        self::assertTrue($run['ok'], (string) wp_json_encode($run));
        self::assertSame('Hi, Ada at ' . wp_date('Y-m-d'), $run['result']);
        self::assertGreaterThan(0, self::assoc($run['used'])['steps'] ?? 0);

        $saved = $this->data($this->request('PUT', '/extensions/greeter/settings', ['settings' => ['greeting' => 'Hello', 'token' => 's3cret', 'loud' => true]], nonce: true));
        $settings = array_column($this->rows($saved['settings']), null, 'key');
        self::assertSame('Hello', self::assoc($settings['greeting'] ?? null)['value'] ?? null);
        self::assertTrue(self::assoc($settings['token'] ?? null)['set'] ?? null, 'the secret is set');
        self::assertNotSame('s3cret', self::assoc($settings['token'] ?? null)['value'] ?? null, 'the secret never comes back');
        self::assertSame(422, $this->request('PUT', '/extensions/greeter/settings', ['settings' => ['greeting' => '']], nonce: true)->get_status(), 'a required setting cannot be emptied');

        // A changed file does not run until enabled again.
        $changed = $this->data($this->request('PUT', '/extensions/greeter/files/' . rawurlencode('main.ocs'), ['content' => str_replace('Hi, ', 'Hello, ', self::SCRIPT)], nonce: true));
        self::assertTrue($changed['stale']);
        $export = $this->data($this->request('GET', '/extensions/greeter/export'));
        self::assertSame(['greeter', 'Greeter', 'orillacart-extension/1'], [$export['id'], $export['name'], $export['format']]);
        self::assertSame(['main.ocs'], array_keys((array) $export['files']));

        $disabled = $this->data($this->request('POST', '/extensions/greeter/disable', nonce: true));
        self::assertSame('disabled', $disabled['state']);
        self::assertSame(204, $this->request('DELETE', '/extensions/greeter', nonce: true)->get_status());
        self::assertSame(404, $this->request('GET', '/extensions/greeter')->get_status());

        // Import puts it back as a draft.
        $imported = $this->data($this->request('POST', '/extensions/import', self::assoc($export), nonce: true));
        self::assertSame(['greeter', 'draft'], [$imported['id'], $imported['state']]);
        self::assertSame(['greeter'], array_column($this->rows($this->data($this->request('GET', '/extensions'))['extensions']), 'id'));
    }

    public function testFilesAreCheckedWithoutBeingSaved(): void
    {
        $answer = $this->data($this->request('POST', '/script/check', ['files' => ['a.ocs' => "fn ok() { return 1 }\n", 'b.ocs' => "fn bad() {\n  return nothing()\n}\n", 'templates/x.html.twig' => '{{ x }}'], 'id' => 'draft'], nonce: true));

        self::assertSame([['file' => 'b.ocs', 'line' => 2, 'column' => 10, 'message' => 'Unknown name "nothing"']], $answer['findings']);
        self::assertSame(['findings' => []], $this->data($this->request('POST', '/script/check', ['files' => ['main.ocs' => "fn ok() { return 1 }\n"]], nonce: true)));
        self::assertSame(200, $this->request('POST', '/script/check', ['files' => []], nonce: true)->get_status(), 'nothing to check is fine');
        $reference = $this->data($this->request('GET', '/script-reference'));
        self::assertContains('let', self::assoc($reference['keywords']));
        self::assertContains('product_after_details', self::assoc($reference['slots']));
    }

    public function testAnExtensionMustDeclareWhatItNeedsAndTheManagerMustGrantIt(): void
    {
        $this->request('POST', '/extensions', ['id' => 'reader', 'name' => 'Reader', 'files' => ['main.ocs' => "extension \"reader\" { needs: { orders: read } }\nfn count() { return 1 }\n"]], nonce: true);

        $refused = $this->request('POST', '/extensions/reader/enable', ['grant' => []], nonce: true);
        self::assertSame(422, $refused->get_status());
        self::assertStringContainsString('orders:read', self::string($this->data($refused)['message']));

        $enabled = $this->data($this->request('POST', '/extensions/reader/enable', ['grant' => ['orders:read']], nonce: true));
        self::assertSame(['orders:read'], $enabled['granted']);

        $reference = $this->data($this->request('GET', '/script-reference'));
        self::assertContains('upper', (array) $reference['filters']);
        self::assertContains('money', array_column($this->rows($reference['functions']), 'name'));
    }

    public function testTheIdAndTheFileNamesAreChecked(): void
    {
        self::assertSame(422, $this->request('POST', '/extensions', ['id' => 'Bad Id', 'name' => 'X'], nonce: true)->get_status());
        $this->request('POST', '/extensions', ['id' => 'ok', 'name' => 'Ok'], nonce: true);
        self::assertSame(422, $this->request('PUT', '/extensions/ok/files/' . rawurlencode('../evil.php'), ['content' => ''], nonce: true)->get_status());
        self::assertSame(422, $this->request('PUT', '/extensions/ok/files/' . rawurlencode('script.php'), ['content' => ''], nonce: true)->get_status());
        self::assertSame(422, $this->request('POST', '/extensions', ['id' => 'ok', 'name' => 'Twice'], nonce: true)->get_status(), 'an id taken');
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        $rows = [];
        foreach (\is_array($value) ? $value : [] as $row) {
            $rows[] = self::assoc($row);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function assoc(mixed $value): array
    {
        $assoc = [];
        foreach (\is_array($value) ? $value : [] as $key => $item) {
            $assoc[(string) $key] = $item;
        }

        return $assoc;
    }
}
