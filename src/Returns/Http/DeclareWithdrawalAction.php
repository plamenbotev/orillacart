<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Http;

use OrillaCart\Returns\Application\Dto\ConfirmWithdrawal;
use OrillaCart\Returns\Application\WithdrawalRequests;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** "Confirm withdrawal", from the link emailed to the order's address: the consumer's declaration, acknowledged at once by email. */
#[Route('/withdrawals', methods: ['POST'], capability: Route::PUBLIC, guard: 'withdrawals')]
final readonly class DeclareWithdrawalAction implements RequestHandlerInterface
{
    public function __construct(
        private WithdrawalRequests $withdrawals,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->withdrawals->withdraw($this->input->body($request, ConfirmWithdrawal::class)), 201);
    }
}
