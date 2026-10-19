<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Http;

use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * An order's receipt, for whoever holds a link to it the shop made: the customer gets one when ordering and with
 * each email, so no account is needed. Never cached, nor its address sent on.
 */
#[Route('/receipts/{receipt}', methods: ['GET'], capability: Route::PUBLIC, requirements: ['receipt' => ReceiptLinks::PATTERN], guard: 'receipts')]
final readonly class ShowReceiptAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderService $orders,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $receipt = $request->getAttribute('receipt');

        return $this->responder->ok($this->orders->byReceipt(\is_string($receipt) ? $receipt : ''))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
