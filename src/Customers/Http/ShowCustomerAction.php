<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/customers/{id}', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ShowCustomerAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->customers->customer(PathParameter::id($request)));
    }
}
