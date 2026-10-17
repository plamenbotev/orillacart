<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Http;

use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/orders', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListOrdersAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderService $orders,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->orders->orders($this->input->query($request, OrderQuery::class)));
    }
}
