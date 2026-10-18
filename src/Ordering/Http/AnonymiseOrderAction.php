<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Http;

use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The customer asked to be forgotten: the order keeps its sums for the books, without their details.
 */
#[Route('/orders/{id}/anonymise', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class AnonymiseOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderService $orders,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->orders->anonymise(PathParameter::id($request), $this->authorization->userId()));
    }
}
