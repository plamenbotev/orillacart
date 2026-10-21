<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Routing;

/**
 * Declares an action class as the handler of one route. Only declared routes exist.
 *
 * Every route states who may call it: a capability, or Route::PUBLIC for visitors.
 * Requests with unsafe methods (POST, PUT, PATCH, DELETE) must carry a nonce for $nonce, except on a public
 * route marked $signed: one a payment provider calls server to server, whose action verifies the provider's
 * signature itself.
 *
 *     #[Route('/settings', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
 *     #[Route('/products/{id}', methods: ['GET'], capability: Route::PUBLIC, requirements: ['id' => '\d+'])]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /** Anyone may call the route, including visitors who are not logged in. */
    public const string PUBLIC = 'public';

    /** The nonce action the WordPress REST API sends in the X-WP-Nonce header. */
    public const string REST_NONCE = 'wp_rest';

    /**
     * @param string                $path         path below the plugin's REST namespace, placeholders as {name}
     * @param list<string>          $methods      HTTP methods
     * @param string                $capability   WordPress capability the user needs, or Route::PUBLIC
     * @param string                $nonce        nonce action checked for unsafe methods
     * @param array<string, string> $requirements regular expression per placeholder; [^/]+ when absent
     * @param bool                  $signed       the action verifies the caller's signature; no nonce (public routes only)
     * @param string|null           $guard        a form visitors send, guarded by name: rate limits, the honeypot
     *                                            and add-ons' captcha checks (GuardMiddleware)
     * @param bool                  $changes      false for an unsafe method that only works something out (a quote,
     *                                            a preview): it is not written into the change log
     */
    public function __construct(
        public string $path,
        public array $methods,
        public string $capability,
        public string $nonce = self::REST_NONCE,
        public array $requirements = [],
        public bool $signed = false,
        public ?string $guard = null,
        public bool $changes = true,
    ) {}
}
