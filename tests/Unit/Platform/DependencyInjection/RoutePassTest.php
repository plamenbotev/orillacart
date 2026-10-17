<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\DependencyInjection;

use OrillaCart\Platform\DependencyInjection\RoutePass;
use OrillaCart\Shared\Http\Routing\ActionDispatcher;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Tests\Fixtures\Http\DuplicateProductAction;
use OrillaCart\Tests\Fixtures\Http\NotAHandlerAction;
use OrillaCart\Tests\Fixtures\Http\ProductAction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

final class RoutePassTest extends TestCase
{
    public function testCollectsEveryRouteOfAnAction(): void
    {
        $container = $this->container([ProductAction::class]);

        new RoutePass()->process($container);

        $container->getDefinition(RouteCollection::class)->setPublic(true);
        $container->compile();
        $routes = $container->get(RouteCollection::class);
        self::assertInstanceOf(RouteCollection::class, $routes);

        // sorted by path, then method
        self::assertEquals(
            [
                new RouteDefinition(ProductAction::class, '/products/{id}', ['DELETE'], 'manage_orillacart', 'wp_rest', ['id' => '\d+']),
                new RouteDefinition(ProductAction::class, '/products/{id}', ['GET'], Route::PUBLIC, 'wp_rest', ['id' => '\d+']),
            ],
            $routes->all(),
        );
    }

    public function testTwoActionsOnTheSameRouteFailTheBuild(): void
    {
        $container = $this->container([ProductAction::class, DuplicateProductAction::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GET /products/(?P<id>\d+)');

        new RoutePass()->process($container);
    }

    public function testAnActionMustHandleRequests(): void
    {
        $container = $this->container([NotAHandlerAction::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        new RoutePass()->process($container);
    }

    /**
     * @param list<class-string> $actions
     */
    private function container(array $actions): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(RouteCollection::class, RouteCollection::class);
        foreach ($actions as $action) {
            $container->register($action, $action)->addTag(ActionDispatcher::ROUTE_TAG);
        }

        return $container;
    }
}
