<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Http;

use OrillaCart\Ordering\Application\OrderActions;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/orders/{id}/actions/{action}', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'action' => '[a-z][a-z0-9_]{0,39}'])]
final readonly class RunOrderActionAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderActions $actions,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->actions->run(PathParameter::id($request), PathParameter::string($request, 'action'), $this->authorization->userId()));
    }
}
