<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Sends an invitation's link again, as a new one: once a day at most, or once the last stopped working (422 orillacart_invitation_recent). */
#[Tool(risk: Tool::CUSTOMERS)]
#[Route('/customer-invitations/{id}/resend', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ResendCustomerInvitationAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->customers->resendInvitation(PathParameter::id($request)));
    }
}
