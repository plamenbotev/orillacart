<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\WordPress\Files\LocalPrivateFiles;
use OrillaCart\Platform\WordPress\Files\WebServerFileDelivery;
use OrillaCart\Shared\Files\PrivateFiles;

/**
 * Downloads handed to the web server: a method is kept only when the site's test request came back with the test
 * file. The web server is played by intercepting that request and acting on the header the site answered with.
 */
final class DownloadDeliveryTest extends RestTestCase
{
    /** Which hand-off header the pretend web server acts on; null for none. */
    private ?string $server = null;
    private ?\Closure $filter = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = $this->webServer(...);
        add_filter('pre_http_request', $this->filter, 10, 3);
        delete_option(WebServerFileDelivery::OPTION);
        $this->actAs('administrator');
    }

    protected function tearDown(): void
    {
        if ($this->filter !== null) {
            remove_filter('pre_http_request', $this->filter);
        }
        delete_option(WebServerFileDelivery::OPTION);
        parent::tearDown();
    }

    public function testOnlyShopManagersChooseHowDownloadsAreSent(): void
    {
        wp_set_current_user(0);
        self::assertSame(401, $this->request('GET', '/download-delivery')->get_status());
        self::assertSame(401, $this->request('PUT', '/download-delivery', ['method' => 'php'])->get_status());
        $this->actAs('editor');
        self::assertSame(403, $this->request('PUT', '/download-delivery', ['method' => 'php'], nonce: true)->get_status());
    }

    public function testAMethodTheWebServerDoesNotFollowIsNotKept(): void
    {
        $answer = $this->data($this->request('PUT', '/download-delivery', ['method' => 'x_sendfile'], nonce: true));

        self::assertSame(['php', false], [$answer['method'], $answer['works']]);
        self::assertIsString($answer['server_rule']);
        self::assertStringContainsString('XSendFilePath ', $answer['server_rule']);
        self::assertSame('php', $this->data($this->request('GET', '/download-delivery'))['method']);
        self::assertNull($this->delivery()->handOff('0123456789abcdef0123456789abcdef'));
    }

    public function testXSendfileIsKeptWhenTheServerSendsTheFile(): void
    {
        $this->server = 'X-Sendfile';
        $answer = $this->data($this->request('PUT', '/download-delivery', ['method' => 'x_sendfile'], nonce: true));
        self::assertSame(['x_sendfile', true], [$answer['method'], $answer['works']]);

        $stored = $this->store('guide');
        try {
            self::assertSame('X-Sendfile: ' . $this->files()->path($stored), $this->delivery()->handOff($stored));
        } finally {
            $this->files()->delete($stored);
        }
    }

    public function testXAccelRedirectGoesThroughTheInternalLocation(): void
    {
        $this->server = 'X-Accel-Redirect';
        $answer = $this->data($this->request('PUT', '/download-delivery', ['method' => 'x_accel_redirect', 'internal_prefix' => 'protected/files'], nonce: true));
        self::assertSame(['x_accel_redirect', '/protected/files/', true], [$answer['method'], $answer['internal_prefix'], $answer['works']]);
        self::assertIsString($answer['server_rule']);
        self::assertStringContainsString("location /protected/files/ {\n    internal;", $answer['server_rule']);

        $stored = $this->store('guide');
        try {
            self::assertSame('X-Accel-Redirect: /protected/files/' . $stored, $this->delivery()->handOff($stored));
        } finally {
            $this->files()->delete($stored);
        }

        self::assertSame(['php', true], array_values(array_intersect_key($this->data($this->request('PUT', '/download-delivery', ['method' => 'php'], nonce: true)), ['method' => 0, 'works' => 0])));
        self::assertNull($this->delivery()->handOff($stored));
    }

    /**
     * The pretend web server: for the site's test request, asks the site for its answer and, when it is the header
     * this server acts on, sends the file the header points at.
     *
     * @param false|array<string, mixed> $preempt
     * @param array<string, mixed>       $args
     *
     * @return false|array<string, mixed>
     */
    private function webServer(false|array $preempt, array $args, string $url): false|array
    {
        $query = [];
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $token = $query[WebServerFileDelivery::PROBE_QUERY] ?? null;
        if (!\is_string($token)) {
            return $preempt;
        }
        $header = $this->delivery()->probe($token);
        $body = '';
        if ($header !== null && $this->server !== null && str_starts_with($header, $this->server . ': ')) {
            $target = substr($header, \strlen($this->server) + 2);
            $path = $this->server === 'X-Sendfile' ? $target : \dirname((string) $this->files()->path(basename($target))) . '/' . basename($target);
            $body = (string) file_get_contents($path);
        }

        return ['headers' => [], 'body' => $body, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }

    private function store(string $content): string
    {
        $temporary = (string) tempnam(get_temp_dir(), 'orillacart-test');
        file_put_contents($temporary, $content);

        return $this->files()->store($temporary, 'guide.pdf')->key;
    }

    /** The same store and option the site uses: both keep their state in WordPress options. */
    private function delivery(): WebServerFileDelivery
    {
        return new WebServerFileDelivery($this->files());
    }

    private function files(): PrivateFiles
    {
        return new LocalPrivateFiles();
    }
}
