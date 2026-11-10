<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The customers the shop invited to make an account who have not yet, the newest first; kept a month after their link stopped working. */
#[Route('/customer-invitations', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListCustomerInvitationsAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->customers->invitations());
    }
}
