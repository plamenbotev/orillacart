<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerGroups;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/customer-groups/{id}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class DeleteCustomerGroupAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerGroups $groups,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->groups->delete(PathParameter::id($request));

        return $this->responder->noContent();
    }
}
