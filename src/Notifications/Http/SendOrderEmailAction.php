<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Http;

use OrillaCart\Notifications\Application\Dto\SendOrderEmail;
use OrillaCart\Notifications\Application\OrderEmails;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Sends one of an order's emails again, such as when the customer lost it; answers whether the mail system took it. */
#[Tool(risk: Tool::CUSTOMERS)]
#[Route('/orders/{id}/emails', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class SendOrderEmailAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderEmails $emails,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok(['sent' => $this->emails->resend(PathParameter::id($request), $this->input->body($request, SendOrderEmail::class))]);
    }
}
