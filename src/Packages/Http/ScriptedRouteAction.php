<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\ScriptResponse;
use OrillaCart\Packages\Application\RouteAnswers;
use OrillaCart\Packages\Application\ScriptedRouteHandler;
use OrillaCart\Packages\Application\ScriptedRoutes;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Output\RedirectResponder;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Http\Security\CallerKeys;
use OrillaCart\Shared\Http\Security\ClientAddress;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\View\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The one action behind every scripted route: the request as a map to the handler, what `respond` made as the
 * response. Not a #[Route]: ScriptedRoutes declares the routes at run time and names this action for each. A
 * visitor's GET of a route that declares `cache` is answered from what an earlier one got (design 74).
 */
final readonly class ScriptedRouteAction implements RequestHandlerInterface
{
    /** Whether a visitor's answer came from the route's cache (hit) or was kept for the next (miss). */
    public const string CACHE_HEADER = 'X-OrillaCart-Cache';
    /** The request headers a script may read. */
    private const array HEADERS = ['accept', 'accept-language', 'content-type', 'user-agent', 'x-request-id'];

    public function __construct(
        private ScriptedRouteHandler $handler,
        private Authorization $authorization,
        private JsonResponder $json,
        private RedirectResponder $redirect,
        private TemplateRenderer $templates,
        private ?RouteAnswers $answers = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = RouteDefinition::fromRequest($request);
        $method = $request->getMethod();
        $map = $this->requestMap($request, $route);
        // The route and its parameters as the handler gets them: the address the request came by need not be one.
        $path = $route->path . '|' . serialize($map['params']);
        $query = $request->getQueryParams();
        // A visitor's answer the route keeps (`cache: seconds`): a signed-in caller's may be theirs alone.
        $cached = $this->authorization->userId() === null ? $this->handler->cached($method, $route->path) : null;
        if ($cached !== null && $this->answers !== null) {
            $known = $this->answers->get($cached[1], $path, $query);
            if ($known !== null) {
                return $this->json->ok($known['body'], $known['status'])->withHeader(self::CACHE_HEADER, 'hit');
            }
        }
        $answer = $this->handler->handle($method, $route->path, $map, $this->caller($request));

        $body = match ($answer->kind) {
            ScriptResponse::JSON => $answer->data,
            // REST answers JSON: a rendered template travels in it, for the caller to put where it wants.
            ScriptResponse::HTML => ['html' => $this->templates->render((string) $answer->template, $answer->data ?? [])],
            default => null,
        };
        if ($body === null) {
            return $answer->kind === ScriptResponse::REDIRECT
                ? $this->redirect->to((string) $answer->url, $answer->status)
                : $this->json->error($answer->status, $answer->status >= 500 ? 'orillacart_extension_failed' : 'orillacart_extension_refused', $answer->message);
        }
        $response = $this->json->ok($body, $answer->status);
        if ($cached === null || $this->answers === null) {
            return $response;
        }
        // Only what went well is kept: a refusal or a failure is asked again.
        if ($answer->status === 200) {
            $this->answers->set($cached[1], $path, $query, ['status' => 200, 'body' => $body], $cached[0]);
        }

        return $response->withHeader(self::CACHE_HEADER, 'miss');
    }

    /** Who sent it: a signed-in caller by account, a visitor by address (an IPv6 /64), as one office's staff are not one caller. */
    private function caller(ServerRequestInterface $request): string
    {
        $userId = $this->authorization->userId();

        return $userId !== null ? 'user:' . $userId : CallerKeys::network('ip:' . ClientAddress::of($request->getServerParams()));
    }

    /** @return array<string, mixed> */
    private function requestMap(ServerRequestInterface $request, RouteDefinition $route): array
    {
        $params = [];
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $route->path, $names);
        foreach ($names[1] as $name) {
            $value = $request->getAttribute($name);
            $params[$name] = \is_scalar($value) ? rawurldecode((string) $value) : null;
        }
        $headers = [];
        foreach (self::HEADERS as $name) {
            if ($request->hasHeader($name)) {
                $headers[$name] = $request->getHeaderLine($name);
            }
        }
        $body = $request->getParsedBody();
        if ($body === null || $body === []) {
            $raw = (string) $request->getBody();
            $decoded = $raw === '' ? null : json_decode($raw, true);
            $body = \is_array($decoded) ? $decoded : ($raw === '' ? null : $raw);
        }
        $userId = $this->authorization->userId();

        return [
            'method' => $request->getMethod(),
            'path' => substr($route->path, \strlen(ScriptedRoutes::PREFIX)),
            'params' => $params,
            'query' => $request->getQueryParams(),
            'body' => $body,
            'headers' => $headers,
            'user' => ['id' => $userId, 'logged_in' => $userId !== null, 'manager' => $this->authorization->isGranted(Capability::MANAGE_SHOP)],
        ];
    }
}
