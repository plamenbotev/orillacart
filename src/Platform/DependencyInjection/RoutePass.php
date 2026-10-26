<?php

declare(strict_types=1);

namespace OrillaCart\Platform\DependencyInjection;

use OrillaCart\Assistant\Application\RouteTools;
use OrillaCart\Shared\Http\Routing\ActionDispatcher;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Collects every #[Route] action into the RouteCollection. Runs at compile time, so a malformed
 * route, an action that is not a request handler or two actions on the same path and method fail the build.
 */
final class RoutePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $routes = [];
        $taken = [];

        foreach (array_keys($container->findTaggedServiceIds(ActionDispatcher::ROUTE_TAG)) as $id) {
            $class = $container->getDefinition($id)->getClass() ?? $id;
            $reflection = $container->getReflectionClass($class);
            if ($reflection === null) {
                throw new InvalidArgumentException(\sprintf('Route action class %s does not exist.', $class));
            }
            if (!$reflection->implementsInterface(RequestHandlerInterface::class)) {
                throw new InvalidArgumentException(\sprintf('Route action %s must implement %s.', $class, RequestHandlerInterface::class));
            }

            foreach ($reflection->getAttributes(Route::class) as $attribute) {
                try {
                    $route = RouteDefinition::fromAttribute($id, $attribute->newInstance());
                } catch (\InvalidArgumentException $e) {
                    throw new InvalidArgumentException($e->getMessage(), 0, $e);
                }

                foreach ($route->methods as $method) {
                    $key = $method . ' ' . $route->pattern();
                    if (isset($taken[$key])) {
                        throw new InvalidArgumentException(\sprintf('Both %s and %s handle %s.', $taken[$key], $id, $key));
                    }
                    $taken[$key] = $id;
                }
                $routes[] = $route->toArray();
            }
        }

        usort($routes, static fn(array $a, array $b): int => [$a['path'], $a['methods']] <=> [$b['path'], $b['methods']]);
        $container->getDefinition(RouteCollection::class)->setArgument('$routes', $routes);

        // The assistant's tools are read from the same routes, once, here: it reads every action's and DTO's code.
        if ($container->hasDefinition(RouteTools::class)) {
            try {
                $tools = RouteTools::describe(array_map(RouteDefinition::fromArray(...), $routes));
            } catch (\LogicException $e) {
                throw new InvalidArgumentException($e->getMessage(), 0, $e);
            }
            $container->getDefinition(RouteTools::class)->setArgument('$tools', $tools);
        }
    }
}
