<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Checkout\Application\Dto\PayOrder;
use OrillaCart\Checkout\Application\PayNow;
use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pays an unpaid order from its receipt, for whoever holds a link to it: where to send the customer next.
 */
#[Route('/receipts/{receipt}/payment', methods: ['POST'], capability: Route::PUBLIC, requirements: ['receipt' => ReceiptLinks::PATTERN], guard: 'receipts')]
final readonly class PayOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private PayNow $payNow,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $receipt = $request->getAttribute('receipt');

        return $this->responder->ok($this->payNow->pay(\is_string($receipt) ? $receipt : '', $this->input->body($request, PayOrder::class)));
    }
}
