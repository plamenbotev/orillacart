<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\AddressBook;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/account/addresses/{address}', methods: ['DELETE'], capability: Capability::ACCOUNT, requirements: ['address' => AddressBook::ID_PATTERN])]
final readonly class RemoveAccountAddressAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->authorization->userId() ?? throw HttpException::unauthorized();

        return $this->responder->ok($this->customers->removeAddress($id, PathParameter::string($request, 'address'))->forCustomer());
    }
}
