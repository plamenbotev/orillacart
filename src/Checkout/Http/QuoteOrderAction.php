<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Checkout\Application\Dto\QuoteOrder;
use OrillaCart\Checkout\Application\ManualOrders;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Prices an order the shop is taking, before it creates it. POST, since the items do not fit a query string.
 */
#[Route('/orders/quote', methods: ['POST'], capability: Capability::MANAGE_SHOP, changes: false)]
final readonly class QuoteOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private ManualOrders $orders,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->orders->quote($this->input->body($request, QuoteOrder::class)));
    }
}
