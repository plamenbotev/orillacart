<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress\Hooks;

use OrillaCart\Integrations\Application\ApiKeys;
use OrillaCart\Integrations\Application\OAuthServer;
use OrillaCart\Integrations\Infrastructure\WordPress\ApiKeyRequest;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A request to the shop's API (/wp-json/orillacart/v1/…) with a key ("Authorization: Bearer oc_…", or the
 * X-OrillaCart-Key header where the host drops Authorization) acts as the shop manager who made the key. It
 * sends no cookies, so it needs no nonce (orillacart_api_key_did_authenticate tells the nonce check). A key the
 * shop has not is refused (401), never served as a visitor. Cookies that come with a key are not asked about:
 * the key decides. Keys open the shop's API only, not WordPress's: the route is read as WordPress reads it (a
 * rest_route parameter wins over the path), and the route WordPress then dispatches is checked again.
 */
#[AsHook('determine_current_user', method: 'user', filter: true, priority: 25)]
#[AsHook('rest_authentication_errors', method: 'errors', filter: true, priority: 20)]
#[AsHook('rest_pre_dispatch', method: 'dispatching', filter: true, priority: 1, arguments: 3)]
final readonly class ApiKeyAuthentication
{
    public function __construct(
        private ApiKeys $keys,
        private OAuthServer $oauth,
        private ApiKeyRequest $request,
    ) {}

    public function user(mixed $userId): mixed
    {
        $this->request->key = null;
        $this->request->refused = false;
        $secret = self::secret();
        if ($secret === null || !self::shopApi()) {
            return $userId;
        }
        // An OAuth access token an AI assistant's app was given, or one of the shop's keys.
        $key = str_starts_with($secret, 'oat_') ? $this->oauth->authenticate($secret) : $this->keys->authenticate($secret);
        if ($key === null || get_userdata($key->userId) === false) {
            $this->request->refused = true;

            return $userId;
        }
        $this->request->key = $key;
        // The key decides who this is, whatever cookies came too: WordPress's REST check would otherwise log out
        // a request with a login cookie and no nonce.
        $GLOBALS['wp_rest_auth_cookie'] = false;
        do_action('orillacart_api_key_did_authenticate', $key->userId, $key->keyId);

        return $key->userId;
    }

    public function errors(mixed $errors): mixed
    {
        if ($this->request->refused && self::shopApi()) {
            return new \WP_Error('orillacart_invalid_api_key', __('The API key is not one the shop has.', 'orillacart'), ['status' => 401]);
        }

        return $errors;
    }

    /**
     * A request a key authenticated reaches the shop's routes only, whatever its address said; one an OAuth token
     * authenticated reaches the MCP endpoint only, the resource it was given for.
     */
    public function dispatching(mixed $result, mixed $server, mixed $request): mixed
    {
        $key = $this->request->key;
        if ($key === null || !$request instanceof \WP_REST_Request) {
            return $result;
        }
        if ($key->oauth && $request->get_route() !== '/' . RouteCollection::REST_NAMESPACE . '/mcp') {
            return new \WP_Error('orillacart_oauth_token_forbidden', __('This token is for the shop\'s MCP endpoint only.', 'orillacart'), ['status' => 403]);
        }
        if (self::ours($request->get_route())) {
            return $result;
        }

        return new \WP_Error('orillacart_api_key_forbidden', __('API keys open the shop\'s API only.', 'orillacart'), ['status' => 403]);
    }

    private static function secret(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (\is_string($header) && preg_match('/^Bearer\s+((?:oc|oat)_\S+)$/i', trim($header), $match) === 1) {
            return $match[1];
        }
        $own = $_SERVER['HTTP_X_ORILLACART_KEY'] ?? null;

        return \is_string($own) && str_starts_with(trim($own), ApiKeys::PREFIX) ? trim($own) : null;
    }

    /** Whether the request is for the shop's own routes: keys open nothing else. */
    private static function shopApi(): bool
    {
        // As WP::parse_request(): a rest_route sent with the request wins over the one in the path.
        $given = $_POST['rest_route'] ?? $_GET['rest_route'] ?? null;
        if ($given !== null) {
            return \is_string($given) && self::ours($given);
        }
        $uri = \is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '';
        $path = wp_parse_url($uri, \PHP_URL_PATH);
        $base = rtrim((string) wp_parse_url(home_url('/'), \PHP_URL_PATH), '/') . '/' . rest_get_url_prefix();

        return \is_string($path) && str_starts_with($path, $base . '/') && self::ours(substr($path, \strlen($base)));
    }

    private static function ours(string $route): bool
    {
        return str_starts_with($route, '/' . RouteCollection::REST_NAMESPACE . '/') && !str_contains($route, '..');
    }
}
