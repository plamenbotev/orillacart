<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\AddressBook;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\SaveAddress;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop saves a new address in a customer's address book, or changes one. */
#[Tool(path: '/customers/{id}/addresses', name: 'add_customer_address')]
#[Tool(path: '/customers/{id}/addresses/{address}', name: 'change_customer_address')]
#[Route('/customers/{id}/addresses', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
#[Route('/customers/{id}/addresses/{address}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'address' => AddressBook::ID_PATTERN])]
final readonly class SaveCustomerAddressAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $address = $request->getAttribute('address');

        return $this->responder->ok($this->customers->saveAddress(PathParameter::id($request), \is_string($address) ? $address : null, $this->input->body($request, SaveAddress::class)));
    }
}
