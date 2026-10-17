<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\RegisterAccount;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A visitor makes an account; the browser is logged in to it.
 */
#[Route('/accounts', methods: ['POST'], capability: Route::PUBLIC)]
final readonly class RegisterAccountAction implements RequestHandlerInterface
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

        return $this->responder->ok($this->customers->register($this->input->body($request, RegisterAccount::class)), 201);
    }
}
