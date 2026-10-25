<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Http;

use OrillaCart\Returns\Application\Dto\FindWithdrawableOrder;
use OrillaCart\Returns\Application\Returns;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The withdrawal function's first step: the order by its number and email, and what of it is left to withdraw. */
#[Route('/withdrawals/orders', methods: ['POST'], capability: Route::PUBLIC, guard: 'withdrawals', changes: false)]
final readonly class FindWithdrawableOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private Returns $returns,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->returns->withdrawable($this->input->body($request, FindWithdrawableOrder::class)));
    }
}
