<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/account/orders/{id}', methods: ['GET'], capability: Capability::ACCOUNT, requirements: ['id' => '\d+'])]
final readonly class ShowAccountOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderService $orders,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->orders->customerOrder($this->authorization->userId() ?? throw HttpException::unauthorized(), PathParameter::id($request)));
    }
}
