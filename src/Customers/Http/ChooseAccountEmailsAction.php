<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\ChooseEmails;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The customer chooses the newsletter, and the reminders of carts they leave. */
#[Route('/account/emails', methods: ['PUT'], capability: Capability::ACCOUNT)]
final readonly class ChooseAccountEmailsAction implements RequestHandlerInterface
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

        return $this->responder->ok($this->customers->chooseEmails($id, $this->input->body($request, ChooseEmails::class)));
    }
}
