<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Middleware;

use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\HttpKernel;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Security\ClientAddress;
use OrillaCart\Shared\Http\Security\HumanCheck;
use OrillaCart\Shared\Http\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Guards the forms visitors send (a route's guard): each caller, by address and by cart, sends so many in a while;
 * the shop's forms carry a field people leave empty (HONEYPOT) and programs fill in; and add-ons check the rest,
 * as captcha plugins do. The honeypot leaves the body before the action reads it.
 */
#[AutoconfigureTag(HttpKernel::MIDDLEWARE_TAG)]
final readonly class GuardMiddleware implements MiddlewareInterface
{
    public const string HONEYPOT = 'orillacart_website';
    private const string CART_COOKIE = 'orillacart_cart';

    public function __construct(
        private RateLimiter $limits,
        private HumanCheck $humans,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $guard = RouteDefinition::fromRequest($request)->guard;
        if ($guard === null) {
            return $handler->handle($request);
        }
        $callers = ['ip:' . ClientAddress::of($request->getServerParams())];
        $cart = $request->getCookieParams()[self::CART_COOKIE] ?? null;
        if (\is_string($cart) && $cart !== '') {
            $callers[] = 'cart:' . hash('sha256', $cart);
        }
        foreach ($callers as $caller) {
            $wait = $this->limits->hit($guard, $caller);
            if ($wait !== null) {
                throw new HttpException(429, 'orillacart_too_many_requests', 'That was a lot at once. Wait a little, then try again.', ['retry_after' => $wait]);
            }
        }

        $body = $request->getParsedBody();
        if (\is_array($body)) {
            $filled = $body[self::HONEYPOT] ?? '';
            unset($body[self::HONEYPOT]);
            $request = $request->withParsedBody($body);
            $refusal = \is_string($filled) && trim($filled) === '' ? $this->humans->refusal($guard, $body) : 'We could not take this. Try again.';
            if ($refusal !== null) {
                throw new HttpException(422, 'orillacart_refused', $refusal);
            }
        }

        return $handler->handle($request);
    }
}
