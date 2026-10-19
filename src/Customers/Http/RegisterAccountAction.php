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
 * A visitor asks for an account. The answer is the same whether the email has one or not; the email tells them.
 */
#[Route('/accounts', methods: ['POST'], capability: Route::PUBLIC, guard: 'accounts')]
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

        $this->customers->requestAccount($this->input->body($request, RegisterAccount::class));

        return $this->responder->ok(['message' => 'Look in your email for the link to make your account.'], 202);
    }
}
