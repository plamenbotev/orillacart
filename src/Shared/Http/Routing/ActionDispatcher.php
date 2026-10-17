<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Routing;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Last step of the pipeline: builds the matched route's action and lets it handle the request.
 * Only services declared with #[Route] are in the locator.
 */
final readonly class ActionDispatcher implements RequestHandlerInterface
{
    public const string ROUTE_TAG = 'orillacart.route';

    public function __construct(
        #[AutowireLocator(self::ROUTE_TAG)]
        private ContainerInterface $actions,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $action = $this->actions->get(RouteDefinition::fromRequest($request)->action);
        if (!$action instanceof RequestHandlerInterface) {
            throw new \LogicException(\sprintf('Action %s is not a request handler.', get_debug_type($action)));
        }

        return $action->handle($request);
    }
}
