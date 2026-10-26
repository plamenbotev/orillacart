<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerGroups;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop's customer groups by name; nothing changes. */
#[Route('/customer-groups', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListCustomerGroupsAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerGroups $groups,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->groups->groups());
    }
}
