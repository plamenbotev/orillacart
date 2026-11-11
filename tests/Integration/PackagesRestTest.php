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
          token: secret { label: "Token", goes_with: [] }
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

    public function testATestNamingAProductTheShopHasNotFailsAloneAndTheOthersStillRun(): void
    {
        $script = <<<'OCS'
            extension "priced" { name: "Priced" }
            object "price" { fields: { product: product, note: text } }

            test "prices a product of another shop" fn() {
              objects.price.create({ product: 987654, note: "elsewhere" })
            }
            test "keeps a price without a product" fn() {
              objects.price.create({ note: "kept" })
              assert objects.price.count({}) == 1
            }
            OCS;
        $installed = $this->request('POST', '/extensions', ['id' => 'priced', 'name' => 'Priced', 'files' => ['main.ocs' => $script]], nonce: true);
        self::assertSame(201, $installed->get_status(), (string) wp_json_encode($installed->get_data()));

        $tests = $this->rows($this->data($this->request('POST', '/extensions/priced/test', [], nonce: true))['tests']);

        self::assertFalse($tests[0]['ok']);
        self::assertIsString($tests[0]['message']);
        self::assertStringContainsString('There is no product 987654 in this shop', $tests[0]['message'], 'refused before the database, with which');
        self::assertSame([true, null], [$tests[1]['ok'], $tests[1]['message']], 'the next test still reaches the database');
        $this->request('DELETE', '/extensions/priced', nonce: true);
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

        // The settings follow the files it runs: an edit that makes the secret plain text shows it no sooner, and
        // a setting only the edit declares is offered but not yet required.
        $edit = str_replace(['token: secret { label: "Token", goes_with: [] }', 'loud: boolean { label: "Loud" }'], ['token: text { label: "Token" }', "loud: boolean { label: \"Loud\" }\n  region: text { label: \"Region\", required: true }"], self::SCRIPT);
        $shown = array_column($this->rows($this->data($this->request('PUT', '/extensions/greeter/files/' . rawurlencode('main.ocs'), ['content' => $edit], nonce: true))['settings']), null, 'key');
        self::assertSame(['secret', '', true], [self::assoc($shown['token'] ?? null)['type'] ?? null, self::assoc($shown['token'] ?? null)['value'] ?? null, self::assoc($shown['token'] ?? null)['set'] ?? null], 'still the running files\' secret');
        self::assertArrayHasKey('region', $shown);
        $kept = $this->request('PUT', '/extensions/greeter/settings', ['settings' => ['greeting' => 'Hey']], nonce: true);
        self::assertSame(200, $kept->get_status(), 'the edit\'s required setting waits for it to be enabled: ' . wp_json_encode($kept->get_data()));
        $kept = array_column($this->rows($this->data($kept)['settings']), null, 'key');
        self::assertTrue(self::assoc($kept['token'] ?? null)['set'] ?? null, 'the running files\' secret is kept');
        $region = $this->request('PUT', '/extensions/greeter/settings', ['settings' => ['greeting' => 'Hey', 'region' => 'EU']], nonce: true);
        self::assertSame(200, $region->get_status(), 'the edit\'s setting can be set before: ' . wp_json_encode($region->get_data()));
        $this->request('PUT', '/extensions/greeter/files/' . rawurlencode('main.ocs'), ['content' => str_replace('Hi, ', 'Hello, ', self::SCRIPT)], nonce: true);

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

    public function testAnExtensionsWordsAreListedAndItsTemplatesChecked(): void
    {
        $script = "extension \"worded\" { name: \"Worded\" }\nsettings { greeting: text { label: \"Greeting\" } }\nfn hello() { return __(\"Free shipping\") ~ \" \" ~ language() }\n";
        $this->request('POST', '/extensions', ['id' => 'worded', 'name' => 'Worded', 'files' => ['main.ocs' => $script, 'templates/note.html.twig' => "{{ _n('%d day', '%d days', 2) }}"]], nonce: true);

        $words = $this->data($this->request('GET', '/extensions/worded/translations'));
        self::assertSame([], $words['languages'], 'one language: nothing to translate into');
        self::assertSame([['Worded', null], ['Greeting', null], ['Free shipping', null], ['%d day', '%d days']], array_map(static fn(array $w): array => [$w['text'], $w['plural']], $this->rows($words['words'])));
        self::assertSame(['main.ocs, line 3'], $this->rows($words['words'])[2]['where']);
        self::assertSame(422, $this->request('PUT', '/extensions/worded/translations', ['translations' => ['0123456789abcdef' => ['de_DE' => 'x']]], nonce: true)->get_status(), 'a word the extension does not have');

        // In the site's language the words stand as written, or as the plugin translates them.
        $this->request('POST', '/extensions/worded/enable', ['grant' => []], nonce: true);
        $run = $this->data($this->request('POST', '/extensions/worded/run', ['function' => 'hello'], nonce: true));
        self::assertSame(__('Free shipping', 'orillacart') . ' ' . determine_locale(), $run['result']);
        self::assertSame([], $this->data($this->request('GET', '/extensions/worded/export'))['translations']);

        // A template that does not compile is a finding, words not written as strings among it.
        $bad = $this->data($this->request('PUT', '/extensions/worded/files/' . rawurlencode('templates/note.html.twig'), ['content' => "<p>\n{{ __(name) }}</p>"], nonce: true));
        $findings = $bad['findings'];
        self::assertIsArray($findings);
        self::assertCount(1, $findings);
        self::assertStringStartsWith('templates/note.html.twig: "__" takes the words themselves', self::string($findings[0]));
        self::assertStringEndsWith('(line 2, column 1)', self::string($findings[0]));
        $this->request('DELETE', '/extensions/worded', nonce: true);
    }

    public function testAnExtensionIsInstalledFromAZipAndExportedAsOne(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'oc-extension');
        if ($path === false) {
            self::fail('no temporary file');
        }
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        $zip->addFromString('zipped-main/main.ocs', "extension \"zipped\" { name: \"Zipped\", version: \"0.3.0\" }\nfn one() { return 1 }\n");
        $zip->addFromString('zipped-main/templates/note.html.twig', '<p>{{ __("Thanks") }}</p>');
        $zip->addFromString('zipped-main/LICENSE', 'MIT');
        $zip->addFromString('__MACOSX/zipped-main/._main.ocs', "\0");
        $zip->close();

        $uploaded = $this->upload('/extensions/upload', $path, 'zipped.zip');
        self::assertSame(201, $uploaded->get_status(), (string) wp_json_encode($uploaded->get_data()));
        $answer = $this->data($uploaded);
        $extension = self::assoc($answer['extension']);
        self::assertSame(['zipped', 'Zipped', '0.3.0', 'draft', []], [$extension['id'], $extension['name'], $extension['version'], $extension['state'], $extension['findings']], 'named by its extension block');
        self::assertSame(['main.ocs', 'templates/note.html.twig'], array_column($this->rows($extension['files']), 'path'));
        self::assertSame(['zipped-main/LICENSE'], $answer['skipped']);

        // The export is a zip with extension.json, and installs again over the same extension.
        $archive = $this->data($this->request('GET', '/extensions/zipped/archive'));
        self::assertSame(['zipped-0.3.0.zip', 'application/zip'], [$archive['filename'], $archive['mime_type']]);
        file_put_contents($path, (string) base64_decode(self::string($archive['content']), true));
        $read = new \ZipArchive();
        self::assertTrue($read->open($path));
        self::assertSame(['id' => 'zipped', 'name' => 'Zipped', 'version' => '0.3.0'], array_intersect_key((array) json_decode((string) $read->getFromName('extension.json'), true), ['id' => 1, 'name' => 1, 'version' => 1]));
        $read->close();
        $again = $this->upload('/extensions/upload', $path, 'zipped-0.3.0.zip');
        self::assertSame([201, []], [$again->get_status(), $this->data($again)['skipped']]);
        self::assertCount(1, array_filter($this->rows($this->data($this->request('GET', '/extensions'))['extensions']), static fn(array $e): bool => $e['id'] === 'zipped'));

        // A zip that names no extension, and a file that is not a zip, are refused.
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        $zip->addFromString('main.ocs', "fn one() { return 1 }\n");
        $zip->close();
        $unnamed = $this->upload('/extensions/upload', $path, 'x.zip');
        self::assertSame([422, 'orillacart_extension_zip_unnamed'], [$unnamed->get_status(), $this->data($unnamed)['code']]);
        file_put_contents($path, 'not a zip');
        self::assertSame(422, $this->upload('/extensions/upload', $path, 'x.zip')->get_status());
        unlink($path);
        $this->request('DELETE', '/extensions/zipped', nonce: true);
    }

    public function testFilesAreCheckedWithoutBeingSaved(): void
    {
        $answer = $this->data($this->request('POST', '/script/check', ['files' => ['a.ocs' => "fn ok() { return 1 }\n", 'b.ocs' => "fn bad() {\n  return nothing()\n}\n", 'templates/x.html.twig' => '{{ x }}'], 'id' => 'draft'], nonce: true));

        self::assertSame([['file' => 'b.ocs', 'line' => 2, 'column' => 10, 'message' => 'Unknown name "nothing"']], $answer['findings']);
        self::assertSame(['findings' => []], $this->data($this->request('POST', '/script/check', ['files' => ['main.ocs' => "fn ok() { return 1 }\n"]], nonce: true)));
        self::assertSame(200, $this->request('POST', '/script/check', ['files' => []], nonce: true)->get_status(), 'nothing to check is fine');
        $reference = $this->data($this->request('GET', '/script-reference'));
        self::assertContains('let', self::assoc($reference['keywords']));
        self::assertArrayHasKey('product_after_details', self::assoc($reference['slots']));
        self::assertArrayHasKey('order_refunded', self::assoc($reference['events']));
        self::assertStringContainsString('section: orders', self::string(self::assoc($reference['templates'])['storefront/account.html.twig']));
        self::assertContains('api_url', self::assoc(self::assoc($reference['template_sandbox'])['functions']));
        self::assertStringStartsWith('# Writing extension scripts', self::string($this->data($this->request('GET', '/script-guide'))['markdown']));
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

    public function testAnExtensionThatPrintsHtmlIsEnabledOnlyByWhoMayPublishIt(): void
    {
        $files = ['main.ocs' => "extension \"boxed\" { name: \"Boxed\" }\nfn one() { return 1 }\n", 'templates/box.html.twig' => '<script>alert(1)</script>'];
        $this->request('POST', '/extensions', ['id' => 'boxed', 'name' => 'Boxed', 'files' => $files], nonce: true);

        $this->actAs('orillacart_shop_manager');
        $refused = $this->request('POST', '/extensions/boxed/enable', ['grant' => []], nonce: true);
        self::assertSame(422, $refused->get_status());
        self::assertSame('orillacart_extension_needs_unfiltered_html', $this->data($refused)['code'], 'a manager may not publish raw HTML, so may not enable what prints it');
        $this->request('POST', '/extensions', ['id' => 'plain', 'name' => 'Plain', 'files' => ['main.ocs' => "extension \"plain\" { name: \"Plain\" }\nfn one() { return 1 }\n"]], nonce: true);
        self::assertSame(200, $this->request('POST', '/extensions/plain/enable', ['grant' => []], nonce: true)->get_status(), 'one with scripts only, a manager enables');

        $this->actAs('administrator');
        self::assertSame(200, $this->request('POST', '/extensions/boxed/enable', ['grant' => []], nonce: true)->get_status());
    }

    public function testAnExtensionsTestsSendNoMailAndSayWhatTheyWouldHaveSent(): void
    {
        $sent = 0;
        $count = static function (mixed $short) use (&$sent): mixed {
            ++$sent;

            return $short;
        };
        add_filter('pre_wp_mail', $count);
        $source = "extension \"mailer\" { name: \"Mailer\", needs: { email: send } }\n\ntest \"mails\" fn() {\n  assert shop.email.send(\"someone@gmail.com\", \"Hi\", \"<p>Hi</p>\")\n}\n";
        try {
            $this->request('POST', '/extensions', ['id' => 'mailer', 'name' => 'Mailer', 'files' => ['main.ocs' => $source]], nonce: true);
            $tests = $this->rows($this->data($this->request('POST', '/extensions/mailer/test', [], nonce: true))['tests']);
        } finally {
            remove_filter('pre_wp_mail', $count);
        }

        self::assertSame(0, $sent, 'a test reaches no one, whatever it declares');
        self::assertTrue($tests[0]['ok'], (string) json_encode($tests[0]));
        self::assertSame([['function' => 'shop.email.send', 'arguments' => ['someone@gmail.com', 'Hi', '<p>Hi</p>']]], $tests[0]['effects']);
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
