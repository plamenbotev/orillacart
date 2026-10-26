<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Integrations\Application\ApiKeys;
use OrillaCart\Integrations\Application\OAuthServer;
use OrillaCart\Integrations\Domain\OAuthError;

/**
 * OAuth 2.1 for AI assistants' apps that log in instead of taking a key: a client registers or describes itself,
 * the owner says yes, the code and its PKCE verifier give tokens for the MCP endpoint only, and a refresh token
 * gives a new pair once.
 */
final class OAuthTest extends RestTestCase
{
    private const string REDIRECT = 'http://127.0.0.1:33418/callback';

    /** @var array<mixed> */
    private array $server = [];
    private mixed $permalinks = null;

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

        $keys = self::kernel()->container()->get(ApiKeys::class);
        self::assertInstanceOf(ApiKeys::class, $keys);
        $listed = $keys->all();
        self::assertCount(1, $listed);
        self::assertSame($client['client_id'], $listed[0]->client);
        $keys->revoke($listed[0]->id);
        self::assertNull($server->authenticate($renewed['access_token']), 'revoking the key ends its tokens');
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
