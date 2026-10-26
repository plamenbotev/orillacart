<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerGroups;
use OrillaCart\Customers\Application\Dto\SaveCustomerGroup;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop adds a customer group, which price rules can give its own prices. */
#[Route('/customer-groups', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateCustomerGroupAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerGroups $groups,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->groups->create($this->input->body($request, SaveCustomerGroup::class)), 201);
    }
}
