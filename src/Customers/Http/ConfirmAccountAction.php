<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\ConfirmAccount;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The visitor followed the emailed link and chose a password: the account is made and the browser logged in.
 */
#[Route('/accounts/confirm', methods: ['POST'], capability: Route::PUBLIC, guard: 'account_links')]
final readonly class ConfirmAccountAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Authorization $authorization,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->authorization->isLoggedIn()) {
            throw new RuleViolation('You are logged in already.', 'orillacart_logged_in');
        }

        return $this->responder->ok($this->customers->confirmAccount($this->input->body($request, ConfirmAccount::class)), 201);
    }
}
