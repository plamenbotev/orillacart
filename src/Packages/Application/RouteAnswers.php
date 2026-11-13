<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

/**
 * Visitors' answers from public GET routes that declare `cache: seconds` (design 74), kept by the request and
 * what else the answer depends on: the language and currency shown, the catalogue's search index (a product's
 * price, stock or text changed), and the extension's build and settings. A repeated request is answered from it
 * without running the extension, so a landing page's filters cost the shop once per choice, not per visitor.
 */
interface RouteAnswers
{
    /**
     * @param string                $scope the extension's part of the key (ScriptedRouteHandler::cached())
     * @param array<array-key, mixed> $query
     *
     * @return array{status: int, body: array<string, mixed>}|null
     */
    public function get(string $scope, string $path, array $query): ?array;

    /**
     * @param array<array-key, mixed>                          $query
     * @param array{status: int, body: array<string, mixed>} $answer
     */
    public function set(string $scope, string $path, array $query, array $answer, int $seconds): void;
}
