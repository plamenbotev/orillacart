<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Cli;

use OrillaCart\Platform\Http\RouteDocumentation;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * Lists the shop's REST routes, as docs/rest-api.md documents them.
 */
final readonly class RoutesCommand implements CliCommand
{
    public function __construct(private RouteDocumentation $documentation) {}

    public static function name(): string
    {
        return 'orillacart routes';
    }

    /**
     * Lists the shop's REST routes: the method, the path, who may call it and its rate limit.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or markdown (the whole documentation, with each route's fields).
     * ---
     * default: table
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function __invoke(array $args, array $options): void
    {
        $format = $options['format'] ?? 'table';
        if ($format === 'markdown') {
            \WP_CLI::log($this->documentation->markdown());

            return;
        }
        $rows = array_map(static fn(array $route): array => [
            'method' => $route['method'],
            'path' => $route['path'],
            'who' => $route['access'],
            'limit' => $route['limit'] ?? '',
            'what' => $route['summary'],
        ], $this->documentation->routes());
        \WP_CLI\Utils\format_items($format, $rows, ['method', 'path', 'who', 'limit', 'what']);
    }
}
