<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Http;

use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Returns\Application\Dto\RequestReturn;
use OrillaCart\Returns\Application\Returns;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The customer holding the order's receipt asks to send some of its items back.
 */
#[Route('/receipts/{receipt}/returns', methods: ['POST'], capability: Route::PUBLIC, requirements: ['receipt' => ReceiptLinks::PATTERN], guard: 'returns')]
final readonly class RequestReturnAction implements RequestHandlerInterface
{
    public function __construct(
        private Returns $returns,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $receipt = $request->getAttribute('receipt');

        return $this->responder->ok($this->returns->request(\is_string($receipt) ? $receipt : '', $this->input->body($request, RequestReturn::class)), 201);
    }
}
