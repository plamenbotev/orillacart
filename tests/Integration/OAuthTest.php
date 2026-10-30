<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Integrations\Application\ApiKeys;
use OrillaCart\Integrations\Application\OAuthServer;
use OrillaCart\Integrations\Domain\OAuthError;
use OrillaCart\Integrations\Infrastructure\WordPress\ForgetUnconnectedOAuthClients;
use OrillaCart\Integrations\Infrastructure\WordPress\Hooks\OAuthConsent;

/**
 * OAuth 2.1 for AI assistants' apps that log in instead of taking a key: a client registers or describes itself,
 * the owner says yes, the code and its PKCE verifier give tokens for the MCP endpoint only, and a refresh token
 * gives a new pair once: presented again, it ends the pair. The consent screen's check answers only a logged-in
 * shop manager; clients that registered and were never connected are forgotten.
 */
final class OAuthTest extends RestTestCase
{
    private const string REDIRECT = 'http://127.0.0.1:33418/callback';

    /** @var array<mixed> */
    private array $server = [];
    private mixed $permalinks = null;
    /** @var list<string> the addresses wp_redirect() was asked to send to */
    private array $redirects = [];
    /** @var list<string> the addresses the shop fetched */
    private array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        $this->permalinks = get_option('permalink_structure');
        update_option('permalink_structure', '/%postname%/');
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_api_keys");
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_oauth_clients");
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = [];
        $this->redirects = $this->fetched = [];
        remove_all_filters('wp_redirect');
        apply_filters('determine_current_user', 0);
        wp_set_current_user(0);
        update_option('permalink_structure', $this->permalinks);
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    public function testARegisteredAppGetsTokensForTheMcpEndpointOnly(): void
    {
        $server = self::oauth();
        $client = $server->register(['client_name' => 'Assistant', 'redirect_uris' => [self::REDIRECT]]);
        self::assertIsString($client['client_id']);
        $admin = $this->actAs('administrator');

        [$verifier, $challenge] = self::pkce();
        // A native app picks a free loopback port each time.
        $request = $server->authorization(self::query($client['client_id'], 'http://127.0.0.1:50999/callback', $challenge));
        $back = $server->approve($request, $admin, true);
        parse_str((string) parse_url($back, \PHP_URL_QUERY), $answer);
        self::assertSame('xyz', $answer['state'] ?? null);
        self::assertSame($server->metadata()['issuer'], $answer['iss'] ?? null);
        $code = $answer['code'] ?? null;
        self::assertIsString($code);

        $tokens = $server->token(['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client['client_id'], 'code_verifier' => $verifier, 'redirect_uri' => 'http://127.0.0.1:50999/callback']);
        self::assertSame('shop:read shop:write', $tokens['scope']);
        self::assertSame(3600, $tokens['expires_in']);
        self::assertIsString($tokens['access_token']);
        self::assertIsString($tokens['refresh_token']);
        self::assertSame('invalid_grant', $this->refused(static fn() => $server->token(['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client['client_id'], 'code_verifier' => $verifier])), 'a code is good once');

        $key = $server->authenticate($tokens['access_token']);
        self::assertNotNull($key);
        self::assertSame($admin, $key->userId);
        self::assertTrue($key->oauth);

        wp_set_current_user(0);
        $this->authenticate($tokens['access_token'], '/wp-json/orillacart/v1/settings');
        self::assertSame(403, $this->request('GET', '/settings')->get_status(), 'an OAuth token opens the MCP endpoint only');
        $this->authenticate($tokens['access_token'], '/wp-json/orillacart/v1/mcp');
        $request = new \WP_REST_Request('POST', self::NAMESPACE . '/mcp');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body('{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertSame(200, rest_do_request($request)->get_status());

        $renewed = $server->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client['client_id']]);
        self::assertIsString($renewed['access_token']);
        self::assertNull($server->authenticate($tokens['access_token']), 'the old access token ends with the refresh');
        self::assertNotNull($server->authenticate($renewed['access_token']));
        self::assertSame('invalid_grant', $this->refused(static fn() => $server->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client['client_id']])), 'a refresh token is good once');
        self::assertNull($server->authenticate($renewed['access_token']), 'the old refresh token presented again means it leaked: the pair it was rotated into ends too (OAuth 2.1 §6.1)');
        self::assertSame('invalid_grant', $this->refused(static fn() => $server->token(['grant_type' => 'refresh_token', 'refresh_token' => $renewed['refresh_token'], 'client_id' => $client['client_id']])), 'the new refresh token ended with it');

        $keys = self::kernel()->container()->get(ApiKeys::class);
        self::assertInstanceOf(ApiKeys::class, $keys);
        $listed = $keys->all();
        self::assertCount(1, $listed, 'the owner\'s yes stays: the app connects again without asking them');
        self::assertSame($client['client_id'], $listed[0]->client);
        [$verifier, $challenge] = self::pkce();
        $again = $server->approve($server->authorization(self::query($client['client_id'], self::REDIRECT, $challenge)), $admin, true);
        parse_str((string) parse_url($again, \PHP_URL_QUERY), $answer);
        self::assertIsString($answer['code'] ?? null);
        $fresh = $server->token(['grant_type' => 'authorization_code', 'code' => $answer['code'], 'client_id' => $client['client_id'], 'code_verifier' => $verifier]);
        self::assertIsString($fresh['access_token']);
        self::assertNotNull($server->authenticate($fresh['access_token']));
        $keys->revoke($listed[0]->id);
        self::assertNull($server->authenticate($fresh['access_token']), 'revoking the key ends its tokens');
    }

    public function testTheConsentCheckAnswersOnlyALoggedInShopManager(): void
    {
        $server = self::oauth();
        $id = self::id($server->register(['client_name' => 'Assistant', 'redirect_uris' => ['https://app.example/callback']]));
        [, $challenge] = self::pkce();
        add_filter('pre_http_request', function (mixed $pre, array $args, string $url): mixed {
            $this->fetched[] = $url;

            return ['headers' => [], 'body' => '', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => [], 'filename' => null];
        }, 10, 3);
        add_filter('wp_redirect', function (string $location): string {
            $this->redirects[] = $location;
            // The hook would exit after the redirect; the exception brings the test back instead.
            throw new \RuntimeException('redirected');

            return $location; // @phpstan-ignore deadCode.unreachable
        });
        self::assertTrue(has_action(OAuthConsent::LOAD) !== false, 'the check is hooked on the screen\'s own load hook, which fires after the menu\'s capability check');

        // A visitor, as admin-ajax.php or admin-post.php would have them when admin_init fires: a redirectable
        // error (response_type=token) must not send them on to the app's address, nor fetch a client document.
        wp_set_current_user(0);
        $_GET = ['page' => OAuthConsent::PAGE, 'response_type' => 'token'] + self::query($id, 'https://app.example/callback', $challenge);
        do_action(OAuthConsent::LOAD);
        $_GET['client_id'] = 'https://app.example/oauth/client.json';
        do_action(OAuthConsent::LOAD);
        self::assertSame('', $this->redirected(), 'a visitor is not redirected through the shop');
        self::assertSame([], $this->fetched, 'nor does the shop fetch a document for them');

        $_GET['client_id'] = $id;
        $this->actAs('subscriber');
        do_action(OAuthConsent::LOAD);
        self::assertSame('', $this->redirected(), 'nor is a logged-in user without the capability');

        $this->actAs('administrator');
        try {
            do_action(OAuthConsent::LOAD);
            self::fail('The shop manager\'s bad request was not sent back to the app.');
        } catch (\RuntimeException) {
        }
        self::assertStringStartsWith('https://app.example/callback?error=unsupported_response_type', $this->redirected());
        self::assertStringNotContainsString("\n", $this->redirected(), 'once');
    }

    public function testAClientNobodyConnectedIsForgottenAfterADay(): void
    {
        $server = self::oauth();
        $stale = self::id($server->register(['client_name' => 'Never connected', 'redirect_uris' => [self::REDIRECT]]));
        $connected = self::id($server->register(['client_name' => 'Connected', 'redirect_uris' => [self::REDIRECT]]));
        $fresh = self::id($server->register(['client_name' => 'Just registered', 'redirect_uris' => [self::REDIRECT]]));
        $admin = $this->actAs('administrator');
        [, $challenge] = self::pkce();
        $server->approve($server->authorization(self::query($connected, self::REDIRECT, $challenge)), $admin, false);
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $wpdb->query("UPDATE {$wpdb->prefix}orillacart_oauth_clients SET created_at = '" . gmdate('Y-m-d H:i:s', time() - 90000) . "' WHERE id IN ('" . esc_sql($stale) . "', '" . esc_sql($connected) . "')");

        self::assertNotFalse(wp_next_scheduled(ForgetUnconnectedOAuthClients::hook()), 'the daily task is on the schedule while the plugin is active');
        self::assertSame(1, $server->forgetUnconnectedClients());

        self::assertNull($server->client($stale), 'registered a day ago, never connected: gone');
        self::assertNotNull($server->client($connected), 'the owner connected it: kept');
        self::assertNotNull($server->client($fresh), 'still within its day: kept');
    }

    /** The limiter counts in rows of its own, one statement each; every hit counts, and the window's row is swept after it. */
    public function testRegistrationsAreCountedAgainstTheLimit(): void
    {
        $limits = static fn(): array => ['oauth_register' => [3, 600]];
        add_filter('orillacart_rate_limits', $limits, 20);
        $statuses = [];
        try {
            for ($i = 0; $i < 4; ++$i) {
                $statuses[] = $this->request('POST', '/oauth/register', ['client_name' => 'Assistant', 'redirect_uris' => [self::REDIRECT]], nonce: true)->get_status();
            }
            $_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(1, 254);
            $statuses[] = $this->request('POST', '/oauth/register', ['client_name' => 'Assistant', 'redirect_uris' => [self::REDIRECT]], nonce: true)->get_status();
        } finally {
            remove_filter('orillacart_rate_limits', $limits, 20);
            unset($_SERVER['REMOTE_ADDR']);
        }
        self::assertSame([201, 201, 201, 429, 201], $statuses, 'the fourth from one address is too many; another address has a window of its own');

        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $rows = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orillacart_rate_limits");
        self::assertSame(2, (int) $rows, 'one row a caller and window');
        $wpdb->query("UPDATE {$wpdb->prefix}orillacart_rate_limits SET expires_at = '2000-01-01 00:00:00'");
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 254);
        add_filter('orillacart_rate_limits', $limits, 20);
        try {
            self::assertSame(201, $this->request('POST', '/oauth/register', ['client_name' => 'Assistant', 'redirect_uris' => [self::REDIRECT]], nonce: true)->get_status());
        } finally {
            remove_filter('orillacart_rate_limits', $limits, 20);
            unset($_SERVER['REMOTE_ADDR']);
        }
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orillacart_rate_limits"), 'a new window sweeps the rows of windows that ended');
    }

    public function testTheRequestIsCheckedBeforeTheOwnerIsAsked(): void
    {
        $server = self::oauth();
        $client = $server->register(['client_name' => 'Assistant', 'redirect_uris' => [self::REDIRECT, 'https://app.example/callback']]);
        [, $challenge] = self::pkce();
        $id = $client['client_id'];
        self::assertIsString($id);

        $other = $this->error(static fn() => $server->authorization(self::query($id, 'https://evil.example/callback', $challenge)));
        self::assertFalse($other->redirectable, 'an address the app did not register is never sent to');
        $port = $this->error(static fn() => $server->authorization(self::query($id, 'https://app.example:8443/callback', $challenge)));
        self::assertFalse($port->redirectable, 'only a loopback address may change its port');
        $noPkce = $this->error(static fn() => $server->authorization(['code_challenge' => null] + self::query($id, self::REDIRECT, $challenge)));
        self::assertSame(['invalid_request', true], [$noPkce->error, $noPkce->redirectable]);
        self::assertStringStartsWith(self::REDIRECT . '?error=invalid_request', (string) $server->refusal($noPkce));
        $target = $this->error(static fn() => $server->authorization(['resource' => 'https://elsewhere.example/mcp'] + self::query($id, self::REDIRECT, $challenge)));
        self::assertSame('invalid_target', $target->error);
        self::assertSame('invalid_client', $this->error(static fn() => $server->authorization(self::query('ocl_unknown', self::REDIRECT, $challenge)))->error);

        self::assertSame('invalid_redirect_uri', $this->refused(static fn() => $server->register(['redirect_uris' => ['http://app.example/callback']])), 'plain http only to this machine');
        self::assertSame('invalid_client_metadata', $this->refused(static fn() => $server->register(['redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'client_secret_basic'])));
    }

    public function testAnAppNamedByItsMetadataDocument(): void
    {
        $server = self::oauth();
        $documents = [
            'https://app.example/oauth/client.json' => ['client_id' => 'https://app.example/oauth/client.json', 'client_name' => 'Example Assistant', 'redirect_uris' => ['https://app.example/callback']],
            'https://liar.example/client.json' => ['client_id' => 'https://other.example/client.json', 'client_name' => 'Liar', 'redirect_uris' => ['https://liar.example/callback']],
        ];
        add_filter('pre_http_request', static fn(mixed $pre, array $args, string $url): mixed => isset($documents[$url])
            ? ['headers' => ['cache-control' => 'max-age=0'], 'body' => (string) wp_json_encode($documents[$url]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null]
            : $pre, 10, 3);

        $client = $server->client('https://app.example/oauth/client.json');
        self::assertNotNull($client);
        self::assertSame('Example Assistant', $client->name);
        self::assertSame(['https://app.example/callback'], $client->redirectUris);
        self::assertNull($server->client('https://liar.example/client.json'), 'its client_id must be its own address');
        self::assertNull($server->client('http://app.example/oauth/client.json'), 'https only');
        self::assertNull($server->client('https://app.example/'), 'with a path');
    }

    public function testTheMetadataIsServedWhereClientsLook(): void
    {
        $resource = $this->request('GET', '/oauth/protected-resource');
        self::assertSame(200, $resource->get_status());
        $metadata = $this->request('GET', '/oauth/.well-known/openid-configuration');
        $data = json_decode((string) wp_json_encode($metadata->get_data()), true);
        self::assertIsArray($data);
        self::assertSame(['S256'], $data['code_challenge_methods_supported']);
        self::assertTrue($data['client_id_metadata_document_supported']);

        $token = new \WP_REST_Request('POST', self::NAMESPACE . '/oauth/token');
        $token->set_body_params(['grant_type' => 'authorization_code', 'code' => 'oac_' . str_repeat('0', 48), 'client_id' => 'ocl_x', 'code_verifier' => str_repeat('a', 43)]);
        $answer = rest_do_request($token);
        self::assertSame(400, $answer->get_status());
        self::assertSame('no-store', $answer->get_headers()['Cache-Control'] ?? null);
        $error = json_decode((string) wp_json_encode($answer->get_data()), true);
        self::assertIsArray($error);
        self::assertSame('invalid_grant', $error['error'] ?? null);
    }

    private static function oauth(): OAuthServer
    {
        $server = self::kernel()->container()->get(OAuthServer::class);
        self::assertInstanceOf(OAuthServer::class, $server);
        self::assertTrue($server->available(), 'pretty permalinks on a local site');

        return $server;
    }

    /** Where wp_redirect() was asked to send, one address a line. */
    private function redirected(): string
    {
        return implode("\n", $this->redirects);
    }

    /**
     * @param array<string, mixed> $registered what register() answered
     *
     * @return string its client_id
     */
    private static function id(array $registered): string
    {
        self::assertIsString($registered['client_id'] ?? null);

        return $registered['client_id'];
    }

    /** @return array{string, string} the verifier and its S256 challenge */
    private static function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    /** @return array<string, string|null> */
    private static function query(string $clientId, string $redirect, string $challenge): array
    {
        return ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'state' => 'xyz', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'shop:read shop:write'];
    }

    /** @param \Closure(): mixed $call */
    private function error(\Closure $call): OAuthError
    {
        try {
            $call();
        } catch (OAuthError $e) {
            return $e;
        }
        self::fail('The call was not refused.');
    }

    /** @param \Closure(): mixed $call */
    private function refused(\Closure $call): string
    {
        return $this->error($call)->error;
    }

    private function authenticate(string $token, string $uri): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $_SERVER['REQUEST_URI'] = $uri;
        $user = apply_filters('determine_current_user', 0);
        wp_set_current_user(\is_int($user) ? $user : 0);
    }
}
