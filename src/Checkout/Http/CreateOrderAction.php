<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Checkout\Application\Dto\CreateOrder;
use OrillaCart\Checkout\Application\ManualOrders;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
