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

/** Emails a customer the shop's welcome: that they have an account, where to log in, and the lost-password link for a password they were never given (an account the shop made with create_customer). */
#[Tool(risk: Tool::CUSTOMERS)]
#[Route('/customers/{id}/welcome', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class SendCustomerWelcomeAction implements RequestHandlerInterface
{
    public function __construct(private CustomerService $customers, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->customers->welcome(PathParameter::id($request)));
    }
}
