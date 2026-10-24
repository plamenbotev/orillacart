<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\AddressBook;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\SaveAddress;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The customer saves a new address in their address book, or changes one. */
#[Route('/account/addresses', methods: ['POST'], capability: Capability::ACCOUNT)]
#[Route('/account/addresses/{address}', methods: ['PUT'], capability: Capability::ACCOUNT, requirements: ['address' => AddressBook::ID_PATTERN])]
final readonly class SaveAccountAddressAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private Authorization $authorization,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->authorization->userId() ?? throw HttpException::unauthorized();
        $address = $request->getAttribute('address');

        return $this->responder->ok($this->customers->saveAddress($id, \is_string($address) ? $address : null, $this->input->body($request, SaveAddress::class))->forCustomer());
    }
}
