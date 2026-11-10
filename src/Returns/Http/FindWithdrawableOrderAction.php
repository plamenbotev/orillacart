<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Http;

use OrillaCart\Returns\Application\Dto\OpenWithdrawal;
use OrillaCart\Returns\Application\WithdrawalRequests;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The withdrawal function, from the link emailed to the order's address: what of the order is left to withdraw from. */
#[Route('/withdrawals/orders', methods: ['POST'], capability: Route::PUBLIC, guard: 'withdrawals', changes: false)]
final readonly class FindWithdrawableOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private WithdrawalRequests $withdrawals,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $command = $this->input->body($request, OpenWithdrawal::class);

        return $this->responder->ok($this->withdrawals->withdrawable(new OpenWithdrawal(PresentedWithdrawal::of($request, $command->token))));
    }
}
