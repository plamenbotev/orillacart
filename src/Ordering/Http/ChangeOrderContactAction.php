<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Http;

use OrillaCart\Ordering\Application\Dto\ChangeOrderContact;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/orders/{id}/contact', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ChangeOrderContactAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderService $orders,
        private RequestMapper $input,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->orders->changeContact(PathParameter::id($request), $this->input->body($request, ChangeOrderContact::class), $this->authorization->userId()));
    }
}
