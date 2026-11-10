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

/** Withdraws an invitation: its link stops working. */
#[Route('/customer-invitations/{id}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class WithdrawCustomerInvitationAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->customers->withdrawInvitation(PathParameter::id($request));

        return $this->responder->noContent();
    }
}
