<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\CustomerQuery;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/customers', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListCustomersAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->customers->customers($this->input->query($request, CustomerQuery::class)));
    }
}
