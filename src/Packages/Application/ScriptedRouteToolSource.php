<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;
use OrillaCart\Assistant\Application\ToolSource;
use OrillaCart\Packages\Application\Dto\ScriptResponse;
use OrillaCart\Shared\Security\Capability;

/**
 * An extension's manager routes as the assistant's tools: `route POST "/refunds" { who: manager }` of the
 * extension `payfast` is the tool `x_payfast_post_refunds`, unless the route says `tool: false`. The route's
 * `title`, `description`, `input` (a JSON schema of the body) and `risk` describe it; without `input` the tool
 * takes `params`, `query` and `body` maps. The handler gets the request map a REST call would give it, as the
 * caller, a manager.
 */
final readonly class ScriptedRouteToolSource implements ToolSource
{
    /** The area of the add-ons' own tools: what they offer is theirs, read-only or not. */
    public const string AREA = 'Extensions';

    public function __construct(private ScriptedRoutes $routes, private ScriptedRouteHandler $handler) {}

    public function tools(): array
    {
        $tools = [];
        foreach ($this->manager() as [$name, $method, $path, $declaration, $loaded]) {
            $props = $declaration['props'];
            $input = $props['input'] ?? null;
            $schema = [];
            foreach (\is_array($input) ? $input : [] as $key => $value) {
                $schema[(string) $key] = $value;
            }
            $risk = $props['risk'] ?? null;
            $risk = \is_array($risk) ? ($risk['$spec'] ?? null) : $risk;
            $title = $props['title'] ?? null;
            $description = $props['description'] ?? null;
            $tools[] = new ToolDefinition(
                $name,
                \is_string($title) ? $title : ucfirst(str_replace('_', ' ', substr($name, 2))),
                \is_string($description) ? $description : \sprintf('%s %s of the extension "%s".', $method, $path, $loaded->package->name()),
                $schema === [] ? self::defaultSchema($path) : $schema,
                $method === 'GET',
                $method === 'DELETE',
                \in_array($method, ['GET', 'PUT', 'DELETE'], true),
                \is_string($risk) ? $risk : ($method === 'DELETE' ? 'deleting' : null),
                self::AREA,
                Capability::MANAGE_SHOP,
                true,
            );
        }

        return $tools;
    }

    public function run(ToolDefinition $tool, array $arguments, Caller $caller): ToolOutcome
    {
        $found = null;
        foreach ($this->manager() as $candidate) {
            if ($candidate[0] === $tool->name) {
                $found = $candidate;
                break;
            }
        }
        if ($found === null) {
            return ToolOutcome::refused(\sprintf('No extension answers the tool %s any more.', $tool->name));
        }
        [, $method, $path, $declaration] = $found;
        if ($caller->readOnly && $method !== 'GET') {
            return ToolOutcome::refused('This key reads only; the tool would change the shop.');
        }
        $input = $declaration['props']['input'] ?? null;
        $structured = !\is_array($input) || $input === [];
        $params = [];
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $names);
        $given = $structured && \is_array($arguments['params'] ?? null) ? $arguments['params'] : $arguments;
        foreach ($names[1] as $placeholder) {
            $value = $given[$placeholder] ?? null;
            $params[$placeholder] = \is_scalar($value) ? (string) $value : null;
        }
        $slug = $found[4]->package->slug();
        $request = [
            'method' => $method,
            'path' => $slug . ($path === '/' ? '' : $path),
            'params' => $params,
            'query' => $structured && \is_array($arguments['query'] ?? null) ? $arguments['query'] : [],
            'body' => $structured ? ($arguments['body'] ?? null) : array_diff_key($arguments, $params),
            'headers' => [],
            'user' => ['id' => $caller->userId, 'logged_in' => true, 'manager' => true],
        ];
        $answer = $this->handler->handle($method, ScriptedRoutes::PREFIX . $slug . ($path === '/' ? '' : $path), $request);

        return match ($answer->kind) {
            ScriptResponse::ERROR => ToolOutcome::refused($answer->message, ['status' => $answer->status]),
            ScriptResponse::HTML => ToolOutcome::done(['template' => $answer->template, 'data' => $answer->data]),
            default => ToolOutcome::done($answer->data),
        };
    }

    /**
     * @return list<array{string, string, string, array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string}, Dto\LoadedPackage}> tool name, method, the route's path within the extension, the declaration and the extension
     */
    private function manager(): array
    {
        $tools = [];
        foreach ($this->routes->manager() as [$loaded, $declaration, $method, $path]) {
            $tool = $declaration['props']['tool'] ?? true;
            if ($tool === false) {
                continue;
            }
            $slug = $loaded->package->slug();
            $suffix = \is_string($tool) ? preg_replace('/[^a-z0-9_]+/', '_', strtolower($tool)) : strtolower($method) . preg_replace('/[^a-z0-9_]+/', '_', strtolower(str_replace(['{', '}'], '', $path)));
            $tools[] = ['x_' . $slug . '_' . trim((string) $suffix, '_'), $method, $path, $declaration, $loaded];
        }

        return $tools;
    }

    /** @return array<string, mixed> */
    private static function defaultSchema(string $path): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $names);
        $params = [];
        foreach ($names[1] as $name) {
            $params[$name] = ['type' => 'string'];
        }
        $properties = [
            'query' => ['type' => 'object', 'description' => 'The query parameters, as a map.', 'additionalProperties' => true],
            'body' => ['type' => 'object', 'description' => 'The request body, as a map.', 'additionalProperties' => true],
        ];
        if ($params !== []) {
            $properties = ['params' => ['type' => 'object', 'description' => 'The values of the path\'s placeholders.', 'properties' => $params, 'required' => array_keys($params)], ...$properties];
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $params === [] ? [] : ['params']];
    }
}
