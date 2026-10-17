<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\SaveAddresses;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/customers/{id}/addresses', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class SaveCustomerAddressesAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->customers->saveAddresses(PathParameter::id($request), $this->input->body($request, SaveAddresses::class)));
    }
}
