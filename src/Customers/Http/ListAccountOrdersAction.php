<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The logged-in customer's orders, newest first. Whatever customer the query names, it is this one.
 */
#[Route('/account/orders', methods: ['GET'], capability: Capability::ACCOUNT)]
final readonly class ListAccountOrdersAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderService $orders,
        private Authorization $authorization,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->authorization->userId() ?? throw HttpException::unauthorized();
        $query = $this->input->query($request, OrderQuery::class);

        return $this->responder->ok($this->orders->orders(new OrderQuery($query->status, null, $id, $query->page, $query->perPage)));
    }
}
