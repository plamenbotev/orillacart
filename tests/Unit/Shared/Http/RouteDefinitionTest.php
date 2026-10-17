<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared\Http;

use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteDefinitionTest extends TestCase
{
    public function testPlaceholdersBecomeNamedGroups(): void
    {
        $route = new RouteDefinition('A', '/products/{id}/images/{name}', ['get'], Route::PUBLIC, 'wp_rest', ['id' => '\d+']);

        self::assertSame('/products/(?P<id>\d+)/images/(?P<name>[^/]+)', $route->pattern());
        self::assertSame(['GET'], $route->methods);
    }

    /**
     * @return iterable<string, array{string, list<string>, string, array<string, string>}>
     */
    public static function invalidRoutes(): iterable
    {
        yield 'relative path' => ['settings', ['GET'], 'manage_orillacart', []];
        yield 'no methods' => ['/settings', [], 'manage_orillacart', []];
        yield 'unknown method' => ['/settings', ['FETCH'], 'manage_orillacart', []];
        yield 'blank capability' => ['/settings', ['GET'], ' ', []];
        yield 'requirement for a missing placeholder' => ['/settings', ['GET'], 'manage_orillacart', ['id' => '\d+']];
    }

    /**
     * @param list<string>          $methods
     * @param array<string, string> $requirements
     */
    #[DataProvider('invalidRoutes')]
    public function testMalformedRoutesAreRejected(string $path, array $methods, string $capability, array $requirements): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RouteDefinition('A', $path, $methods, $capability, 'wp_rest', $requirements);
    }

    public function testOnlyReadMethodsAreSafe(): void
    {
        $route = new RouteDefinition('A', '/x', ['GET'], Route::PUBLIC, 'wp_rest');

        self::assertTrue($route->isSafe('get'));
        self::assertTrue($route->isSafe('HEAD'));
        self::assertFalse($route->isSafe('POST'));
        self::assertFalse($route->isSafe('DELETE'));
    }

    public function testSurvivesTheTripThroughTheCompiledContainer(): void
    {
        $route = new RouteDefinition('A', '/products/{id}', ['GET', 'HEAD'], 'edit_posts', 'orillacart_product', ['id' => '\d+']);

        self::assertEquals($route, RouteDefinition::fromArray($route->toArray()));
    }
}
