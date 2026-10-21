<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress\Hooks;

use OrillaCart\Integrations\Application\ApiKeys;
use OrillaCart\Integrations\Infrastructure\WordPress\ApiKeyRequest;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A request to the shop's API (/wp-json/orillacart/v1/…) with a key ("Authorization: Bearer oc_…", or the
 * X-OrillaCart-Key header where the host drops Authorization) acts as the shop manager who made the key. It
 * sends no cookies, so it needs no nonce (orillacart_api_key_did_authenticate tells the nonce check). A key the
 * shop has not is refused (401), never served as a visitor. Cookies that come with a key are not asked about:
 * the key decides. Keys open the shop's API only, not WordPress's.
 */
#[AsHook('determine_current_user', method: 'user', filter: true, priority: 25)]
#[AsHook('rest_authentication_errors', method: 'errors', filter: true, priority: 20)]
final readonly class ApiKeyAuthentication
{
    public function __construct(
        private ApiKeys $keys,
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
        $key = $this->keys->authenticate($secret);
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

    private static function secret(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (\is_string($header) && preg_match('/^Bearer\s+(oc_\S+)$/i', trim($header), $match) === 1) {
            return $match[1];
        }
        $own = $_SERVER['HTTP_X_ORILLACART_KEY'] ?? null;

        return \is_string($own) && str_starts_with(trim($own), ApiKeys::PREFIX) ? trim($own) : null;
    }

    /** Whether the request is for the shop's own routes: keys open nothing else. */
    private static function shopApi(): bool
    {
        $uri = \is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '';
        $route = \is_string($_GET['rest_route'] ?? null) ? $_GET['rest_route'] : '';
        $namespace = '/' . RouteCollection::REST_NAMESPACE . '/';

        return str_contains($uri, '/' . rest_get_url_prefix() . $namespace) || str_starts_with($route, $namespace);
    }
}
