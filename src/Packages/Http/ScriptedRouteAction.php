<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\ScriptResponse;
use OrillaCart\Packages\Application\ScriptedRouteHandler;
use OrillaCart\Packages\Application\ScriptedRoutes;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Output\RedirectResponder;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\View\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The one action behind every scripted route: the request as a map to the handler, what `respond` made as the
 * response. Not a #[Route]: ScriptedRoutes declares the routes at run time and names this action for each.
 */
final readonly class ScriptedRouteAction implements RequestHandlerInterface
{
    /** The request headers a script may read. */
    private const array HEADERS = ['accept', 'accept-language', 'content-type', 'user-agent', 'x-request-id'];

    public function __construct(
        private ScriptedRouteHandler $handler,
        private Authorization $authorization,
        private JsonResponder $json,
        private RedirectResponder $redirect,
        private TemplateRenderer $templates,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = RouteDefinition::fromRequest($request);
        $answer = $this->handler->handle($request->getMethod(), $route->path, $this->requestMap($request, $route));

        return match ($answer->kind) {
            ScriptResponse::JSON => $this->json->ok($answer->data, $answer->status),
            ScriptResponse::REDIRECT => $this->redirect->to((string) $answer->url),
            ScriptResponse::ERROR => $this->json->error($answer->status, $answer->status >= 500 ? 'orillacart_extension_failed' : 'orillacart_extension_refused', $answer->message),
            // REST answers JSON: a rendered template travels in it, for the caller to put where it wants.
            default => $this->json->ok(['html' => $this->templates->render((string) $answer->template, $answer->data ?? [])], $answer->status),
        };
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
