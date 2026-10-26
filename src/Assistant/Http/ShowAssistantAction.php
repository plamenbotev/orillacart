<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Http;

use OrillaCart\Assistant\Application\AssistantTools;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Integrations\Application\OAuthServer;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The AI assistant's endpoint and every tool there is, by area (the shop's areas, then each add-on's), for the
 * owner to choose from per key.
 */
#[Tool(leftOut: 'The admin\'s own: what the owner chooses a key\'s tools from.')]
#[Route('/assistant', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowAssistantAction implements RequestHandlerInterface
{
    public function __construct(
        private AssistantTools $tools,
        private OAuthServer $oauth,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $areas = [];
        foreach ($this->tools->all() as $tool) {
            $areas[$tool->area] ??= ['area' => $tool->area, 'add_on' => $tool->addOn, 'tools' => []];
            $areas[$tool->area]['tools'][] = self::tool($tool);
        }
        uasort($areas, static fn(array $a, array $b): int => [$a['add_on'], $a['area']] <=> [$b['add_on'], $b['area']]);

        return $this->responder->ok([
            'endpoint' => self::endpoint($request),
            'oauth' => $this->oauth->available(),
            'areas' => array_values($areas),
        ]);
    }

    /** The endpoint's address, beside the route the admin called this one by. */
    private static function endpoint(ServerRequestInterface $request): string
    {
        $uri = (string) $request->getUri()->withQuery('')->withFragment('');
        $self = '/' . RouteCollection::REST_NAMESPACE . '/assistant';
        if (str_ends_with($uri, $self)) {
            return substr($uri, 0, -\strlen('/assistant')) . '/mcp';
        }
        // Without pretty permalinks the route is in the query: ?rest_route=/orillacart/v1/assistant.
        return (string) $request->getUri()->withQuery('rest_route=' . rawurlencode('/' . RouteCollection::REST_NAMESPACE . '/mcp'))->withFragment('');
    }

    /** @return array{name: string, title: string, description: string, risk: ?string, read_only: bool} */
    private static function tool(ToolDefinition $tool): array
    {
        return ['name' => $tool->name, 'title' => $tool->title, 'description' => $tool->description, 'risk' => $tool->risk, 'read_only' => $tool->readOnly];
    }
}
