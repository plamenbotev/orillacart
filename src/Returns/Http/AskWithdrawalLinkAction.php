<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Http;

use OrillaCart\Returns\Application\Dto\AskToWithdraw;
use OrillaCart\Returns\Application\WithdrawalRequests;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The withdrawal function's first step: an order's number and email. The answer is the same whatever they are;
 * the order's own address is sent the link that opens its withdrawal.
 */
#[Route('/withdrawals/links', methods: ['POST'], capability: Route::PUBLIC, guard: 'withdrawals')]
final readonly class AskWithdrawalLinkAction implements RequestHandlerInterface
{
    public const string LIMIT = 'withdrawal_links';

    public function __construct(
        private WithdrawalRequests $withdrawals,
        private RequestMapper $input,
        private JsonResponder $responder,
        private RateLimiter $limits,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // So many links an order's address gets a day, from everyone together.
        $this->withdrawals->ask($this->input->body($request, AskToWithdraw::class), fn(int $order): bool => $this->limits->hit(self::LIMIT, "order:{$order}") === null);

        return $this->responder->ok(['message' => 'If an order has this number and email, the link to withdraw from it is on its way to that email.'], 202);
    }
}
