<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * Design 71: an extension's test or console run debugged, and its live runs captured, each left as a recording to
 * step through; every failure says where it happened, recorded or not.
 */
final class ExtensionDebuggingTest extends RestTestCase
{
    private const array FILES = [
        'main.ocs' => <<<'OCS'
            extension "dbg" { name: "Debugged" }
            settings { token: secret { label: "Token", goes_with: [] } }

            fn total(items) {
              let sum = 0
              for item in items { sum = sum + price(item) }
              return sum
            }

            route GET "/price" { who: public } fn(request) {
              return { total: total([{ unit: 2, qty: (request.query.qty ?? "1")|number }]) }
            }

            test "totals" fn() {
              assert total([{ unit: 2, qty: 3 }]) == 6
            }
            test "refuses big ones" fn() {
              assert total([{ unit: 9, qty: 1 }]) == 9, "nine"
            }
            OCS,
        'price.ocs' => <<<'OCS'
            fn price(item) {
              let unit = item.unit
              debug(settings.get("token") ~ "-" ~ unit)
              if unit * item.qty > 8 { fail error("too much: " ~ unit * item.qty) }
              return unit * item.qty
            }
            OCS,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        $this->request('DELETE', '/extensions/dbg', nonce: true);
        $installed = $this->request('POST', '/extensions', ['id' => 'dbg', 'name' => 'Debugged', 'files' => self::FILES], nonce: true);
        self::assertSame(201, $installed->get_status(), (string) wp_json_encode($installed->get_data()));
        $saved = $this->request('PUT', '/extensions/dbg/settings', ['settings' => ['token' => 'tok-secret-1']], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
    }

    protected function tearDown(): void
    {
        $this->request('DELETE', '/extensions/dbg', nonce: true);
        parent::tearDown();
    }

    public function testATestDebuggedLeavesARecordingWithItsLinesStackAndNoSecret(): void
    {
        $answer = $this->request('POST', '/extensions/dbg/test', ['debug' => 'refuses big ones'], nonce: true);
        self::assertSame(200, $answer->get_status(), (string) wp_json_encode($answer->get_data()));
        $tests = self::rows($answer->get_data(), 'tests');
        $byName = array_column($tests, null, 'name');
        self::assertNull($byName['totals']['recording'], 'only the test asked for is recorded');
        $failed = $byName['refuses big ones'];
        self::assertSame([false, 'Failed with error: too much: 9', 'price.ocs', 4], [$failed['ok'], $failed['message'], $failed['file'], $failed['line']], 'an error says where it happened');
        self::assertSame([['fn' => 'price', 'file' => 'price.ocs', 'line' => 4], ['fn' => 'total', 'file' => 'main.ocs', 'line' => 6], ['fn' => 'test "refuses big ones"', 'file' => 'main.ocs', 'line' => 18]], $failed['stack']);

        $recording = $failed['recording'];
        self::assertIsArray($recording);
        self::assertIsInt($recording['id']);
        self::assertIsArray($recording['sources']);
        self::assertSame(['main.ocs', 'price.ocs'], array_keys($recording['sources']));
        $steps = self::rows($recording, 'steps');
        $lines = array_values(array_filter($steps, static fn(array $s): bool => $s['k'] === 'line'));
        self::assertSame(['main.ocs', 18], [$lines[0]['file'], $lines[0]['line']], 'the test\'s own line first');
        self::assertContains(['price.ocs', 2], array_map(static fn(array $s): array => [$s['file'], $s['line']], $lines));
        $last = end($steps);
        self::assertIsArray($last);
        self::assertSame(['error', 'Failed with error: too much: 9', 'price.ocs', 4], [$last['k'], $last['message'], $last['file'], $last['line']]);

        // Kept, listed, opened and removed.
        $listed = $this->request('GET', '/extensions/dbg/recordings')->get_data();
        $first = self::rows($listed, 'recordings')[0];
        self::assertSame([$recording['id'], 'test', 'test "refuses big ones"', 'error'], [$first['id'], $first['source'], $first['what'], $first['outcome']]);
        $opened = $this->request('GET', '/extensions/dbg/recordings/' . $recording['id'])->get_data();
        self::assertIsArray($opened);
        self::assertSame($steps, self::rows($opened, 'steps'));
        self::assertSame(200, $this->request('DELETE', '/extensions/dbg/recordings/' . $recording['id'], nonce: true)->get_status());
        self::assertSame(404, $this->request('GET', '/extensions/dbg/recordings/' . $recording['id'])->get_status());
    }

    public function testAnAgentDebugsATestSummedUp(): void
    {
        $answer = $this->request('POST', '/extensions/dbg/debug', ['test' => 'refuses big ones'], nonce: true);
        self::assertSame(200, $answer->get_status(), (string) wp_json_encode($answer->get_data()));
        $summary = $answer->get_data();
        self::assertIsArray($summary);
        self::assertSame(['error', 'Failed with error: too much: 9', ['file' => 'price.ocs', 'line' => 4]], [$summary['outcome'], $summary['message'], $summary['ended_at']]);
        self::assertSame(9, (array) $summary['variables'] === [] ? null : ((array) $summary['variables'])['unit']);
        self::assertIsArray($summary['path']);
        self::assertSame('price.ocs:4', end($summary['path']));
        self::assertSame(404, $this->request('POST', '/extensions/dbg/debug', ['test' => 'no such'], nonce: true)->get_status());
    }

    public function testAConsoleRunDebuggedAndTheRunsLogSaysWhere(): void
    {
        $answer = $this->request('POST', '/extensions/dbg/run', ['function' => 'total', 'arguments' => [[['unit' => 5, 'qty' => 2]]], 'debug' => true], nonce: true);
        self::assertSame(200, $answer->get_status(), (string) wp_json_encode($answer->get_data()));
        $run = $answer->get_data();
        self::assertIsArray($run);
        self::assertFalse($run['ok']);
        self::assertSame([['fn' => 'price', 'file' => 'price.ocs', 'line' => 4], ['fn' => 'total', 'file' => 'main.ocs', 'line' => 6]], $run['stack']);
        self::assertSame('console: total', self::map($run['recording'])['what']);

        $runs = $this->request('GET', '/extensions/dbg/runs')->get_data();
        self::assertSame('too much: 10 (at price.ocs:4 in price, from main.ocs:6 in total)', self::rows($runs, 'runs')[0]['message'], 'every failure in the log says where it happened');
    }

    public function testALiveCaptureRecordsTheNextRunsAndEnds(): void
    {
        // The run that fails is the extension's failure, logged as any is.
        $this->expectOutputRegex('/The extension "dbg" failed: too much: 18/');
        self::assertSame(200, $this->request('POST', '/extensions/dbg/enable', ['grant' => []], nonce: true)->get_status());
        $armed = $this->request('POST', '/extensions/dbg/capture', ['what' => 'route', 'runs' => 2, 'minutes' => 5], nonce: true);
        self::assertSame(200, $armed->get_status(), (string) wp_json_encode($armed->get_data()));
        $capture = self::map(self::map($armed->get_data())['capture']);
        self::assertSame(['route', 2], [$capture['what'], $capture['left']]);

        // The REST routes register once per server: a new server reads the extension's routes.
        $GLOBALS['wp_rest_server'] = null;
        $manager = get_current_user_id();
        wp_set_current_user(0);
        foreach (['2', '9', '3'] as $qty) {
            $this->request('GET', '/x/dbg/price?qty=' . $qty);
        }
        wp_set_current_user($manager);

        $listed = $this->request('GET', '/extensions/dbg/recordings')->get_data();
        $live = array_values(array_filter(self::rows($listed, 'recordings'), static fn(array $r): bool => $r['source'] === 'live'));
        self::assertSame([['route GET /price', 'error'], ['route GET /price', 'ok']], array_map(static fn(array $r): array => [$r['what'], $r['outcome']], $live), 'two runs, newest first; the third ran unrecorded');
        self::assertNull(self::map($listed)['capture'], 'used up, it ended by itself');
        // A live run reads the saved settings: the secret one never shows, even inside a text.
        $opened = $this->request('GET', '/extensions/dbg/recordings/' . self::text($live[1]['id']))->get_data();
        $encoded = (string) wp_json_encode($opened);
        self::assertStringNotContainsString('tok-secret-1', $encoded);
        $marked = array_values(array_filter(self::rows($opened, 'steps'), static fn(array $s): bool => $s['k'] === 'debug'));
        self::assertSame('•••', $marked[0]['value'] ?? null);

        $this->request('POST', '/extensions/dbg/capture', ['runs' => 1], nonce: true);
        self::assertSame(200, $this->request('DELETE', '/extensions/dbg/capture', nonce: true)->get_status());
        $after = $this->request('GET', '/extensions/dbg/recordings')->get_data();
        self::assertIsArray($after);
        self::assertNull($after['capture'], 'stopped');
    }

    /** @return array<string, mixed> */
    private static function map(mixed $data): array
    {
        self::assertIsArray($data);

        /** @var array<string, mixed> */
        return $data;
    }

    private static function text(mixed $value): string
    {
        self::assertTrue(\is_int($value) || \is_string($value));

        return (string) $value;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $data, string $key): array
    {
        self::assertIsArray($data);
        $rows = $data[$key] ?? null;
        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> */
        return array_values($rows);
    }
}
