<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The logged-in customer's account: who they are and the addresses checkout fills in.
 */
#[Route('/account', methods: ['GET'], capability: Capability::ACCOUNT)]
final readonly class ShowAccountAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->customers->customer($this->authorization->userId() ?? throw HttpException::unauthorized()));
    }
}
