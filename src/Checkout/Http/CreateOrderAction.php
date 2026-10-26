<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Checkout\Application\Dto\CreateOrder;
use OrillaCart\Checkout\Application\ManualOrders;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The shop takes an order itself, as on the phone or in person: it is priced and takes stock as checkout does. A paid
 * order is marked paid and starts processing; an unpaid one waits on hold. The customer is emailed only when the shop
 * asks.
 */
#[Tool(risk: Tool::MONEY)]
#[Route('/orders', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private ManualOrders $orders,
        private RequestMapper $input,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->orders->create($this->input->body($request, CreateOrder::class), $this->authorization->userId()), 201);
    }
}
