<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Shared\Persistence\LoadedEntities;
use PHPUnit\Framework\TestCase;

/**
 * Calls the plugin's routes through the real WordPress REST server, as a visitor or as a user of a role.
 */
abstract class RestTestCase extends TestCase
{
    protected const string NAMESPACE = '/orillacart/v1';

    public static function setUpBeforeClass(): void
    {
        // The plugin may have been activated before this code existed; activation adds capabilities and tables.
        $lifecycle = self::kernel()->container()->get(PluginLifecycle::class);
        self::assertInstanceOf(PluginLifecycle::class, $lifecycle);
        $lifecycle->activate();
    }

    /** @var list<array{to: string, subject: string, message: string, headers: list<string>, attachments: list<string>, named: array<string, string>}> mail the plugin sent during the test */
    protected array $mails = [];
    private ?\Closure $mailCatcher = null;
    /** @var list<string> files the test hands the REST API as if PHP had received them */
    private array $uploaded = [];
    private ?\Closure $uploadCheck = null;

    protected function setUp(): void
    {
        wp_set_current_user(0);
        $this->mails = [];
        // Emails other tests queued and never sent.
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_jobs WHERE queue = 'mail'");
        // What earlier tests and runs counted against the rate limits: each test starts within them.
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_rate_limits");
        wp_cache_flush();
        // As a new request would: what earlier tests loaded, and what was read once per request, is read again.
        $entities = self::kernel()->container()->get(LoadedEntities::class);
        self::assertInstanceOf(LoadedEntities::class, $entities);
        $entities->forget();
        // One closure, kept, so remove_filter() finds the same one.
        $this->mailCatcher = $this->catchMail(...);
        add_filter('pre_wp_mail', $this->mailCatcher, 10, 2);
    }

    protected function tearDown(): void
    {
        if ($this->mailCatcher !== null) {
            remove_filter('pre_wp_mail', $this->mailCatcher);
        }
        if ($this->uploadCheck !== null) {
            remove_filter('orillacart_genuine_upload', $this->uploadCheck);
            $this->uploadCheck = null;
        }
        $this->uploaded = [];
        wp_set_current_user(0);
        // A test that showed an admin screen would leave is_admin() true for the tests after it.
        $GLOBALS['current_screen'] = null;
    }

    /**
     * Keeps mail instead of sending it: the test site has no mail server.
     *
     * @param array<string, mixed> $mail
     */
    public function catchMail(?bool $short, array $mail): bool
    {
        $to = $mail['to'] ?? '';
        $this->mails[] = [
            'to' => \is_array($to) ? implode(', ', array_filter($to, \is_string(...))) : (\is_string($to) ? $to : ''),
            'subject' => \is_string($mail['subject'] ?? null) ? $mail['subject'] : '',
            'message' => \is_string($mail['message'] ?? null) ? $mail['message'] : '',
            'headers' => array_values(array_filter((array) ($mail['headers'] ?? []), \is_string(...))),
            'attachments' => array_values(array_filter((array) ($mail['attachments'] ?? []), \is_string(...))),
            // Files sent by a name of their own, with how they begin: the shop's PDFs exist only while they go.
            'named' => array_map(static fn(mixed $path): string => \is_string($path) ? (string) file_get_contents($path, length: 5) : '', array_filter((array) ($mail['attachments'] ?? []), \is_string(...), ARRAY_FILTER_USE_KEY)),
        ];

        return true;
    }

    /**
     * The mail sent so far, once the queued emails went out as a worker sends them (requests here run under the
     * command line, which runs nothing after the response). Read through this method: the mail filter fills the
     * list behind static analysis.
     *
     * @return list<array{to: string, subject: string, message: string, headers: list<string>, attachments: list<string>, named: array<string, string>}>
     */
    protected function sentMail(): array
    {
        $runner = self::kernel()->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        while ($runner->work(['mail'], 100, 'test')->count() > 0);

        return $this->mails;
    }

    protected static function kernel(): Kernel
    {
        return new Kernel(new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION));
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function request(string $method, string $route, array $body = [], bool|string $nonce = false): \WP_REST_Response
    {
        $query = [];
        if (str_contains($route, '?')) {
            [$route, $string] = explode('?', $route, 2);
            parse_str($string, $query);
        }
        $request = new \WP_REST_Request($method, self::NAMESPACE . $route);
        $request->set_query_params($query);
        if ($body !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }
        if ($nonce !== false) {
            $request->set_header('X-WP-Nonce', $nonce === true ? wp_create_nonce('wp_rest') : $nonce);
        }

        return rest_do_request($request);
    }

    /**
     * The REST API takes only files PHP received as uploads; a test's own file stands in for one.
     */
    protected function treatAsUploaded(string $path): void
    {
        $this->uploaded[] = $path;
        if ($this->uploadCheck === null) {
            $this->uploadCheck = fn(bool $genuine, string $file): bool => $genuine || \in_array($file, $this->uploaded, true);
            add_filter('orillacart_genuine_upload', $this->uploadCheck, 10, 2);
        }
    }

    /**
     * A multipart request with one file, as a browser sends it.
     *
     * @param array<string, string|list<string>> $fields
     * @param non-empty-string $path
     * @param non-empty-string $name
     */
    protected function upload(string $route, string $path, string $name, array $fields = [], bool $genuine = true): \WP_REST_Response
    {
        if ($genuine) {
            $this->treatAsUploaded($path);
        }
        $request = new \WP_REST_Request('POST', self::NAMESPACE . $route);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_body_params($fields);
        $request->set_file_params(['file' => ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => max(0, (int) filesize($path))]]);

        return rest_do_request($request);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function data(\WP_REST_Response $response): array
    {
        $data = $response->get_data();
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    protected function violations(\WP_REST_Response $response): array
    {
        $data = $this->data($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data['violations']);

        /** @var list<array{field: string, message: string}> */
        return $data['violations'];
    }

    /**
     * Gives an item these levels and no others, as one who loaded its stock saves it: each with the level it has
     * now (`was`), so a level that moved meanwhile is refused (409).
     *
     * @param array<int, ?int> $levels by stockroom
     */
    protected function setStock(string $kind, int $id, array $levels): \WP_REST_Response
    {
        $now = $this->levelsNow($kind, $id);
        $cells = [];
        foreach ($levels + array_fill_keys(array_keys($now), null) as $stockroom => $quantity) {
            $cells[] = ['stockroom_id' => $stockroom, 'quantity' => $quantity, 'was' => $now[$stockroom] ?? null];
        }

        return $this->request('PUT', "/stock/{$kind}/{$id}", ['levels' => $cells], nonce: true);
    }

    /**
     * What a product's save sends of the product as it was loaded: its updated_at, without which a product saved
     * before is refused (409).
     *
     * @return array{updated_at: mixed}
     */
    protected function loaded(int $productId): array
    {
        return ['updated_at' => $this->data($this->request('GET', "/products/{$productId}"))['updated_at'] ?? null];
    }

    /**
     * The stock sheet's cells with the level each has now, as the sheet sends them.
     *
     * @param list<array{kind: string, id: int, stockroom_id: int, quantity: ?int}> $cells
     *
     * @return list<array{kind: string, id: int, stockroom_id: int, quantity: ?int, was: ?int}>
     */
    protected function asLoaded(array $cells): array
    {
        return array_map(fn(array $cell): array => $cell + ['was' => $this->levelsNow($cell['kind'], $cell['id'])[$cell['stockroom_id']] ?? null], $cells);
    }

    /** @return array<int, int> by stockroom */
    private function levelsNow(string $kind, int $id): array
    {
        $now = [];
        $levels = $this->data($this->request('GET', "/stock/{$kind}/{$id}"))['levels'] ?? [];
        foreach (\is_array($levels) ? $levels : [] as $level) {
            if (\is_array($level) && is_numeric($level['stockroom_id'] ?? null) && is_numeric($level['quantity'] ?? null)) {
                $now[(int) $level['stockroom_id']] = (int) $level['quantity'];
            }
        }

        return $now;
    }

    protected function actAs(string $role): int
    {
        $existing = get_user_by('login', 'orillacart_' . $role);
        if ($existing instanceof \WP_User) {
            $id = $existing->ID;
        } else {
            $id = wp_insert_user(['user_login' => 'orillacart_' . $role, 'user_pass' => wp_generate_password(), 'role' => $role]);
            self::assertIsInt($id);
        }
        wp_set_current_user($id);

        return $id;
    }
}
