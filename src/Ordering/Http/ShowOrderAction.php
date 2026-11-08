<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Http;

use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Packages\Application\ExtensionFields;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Shows one order in full: its items, totals, customer, addresses, payment and shipping; nothing changes. */
#[Route('/orders/{id}', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ShowOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderService $orders,
        private ExtensionFields $fields,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = PathParameter::id($request);

        return $this->responder->ok($this->orders->order($id)->withExtensions($this->fields->of('order', $id)));
    }
}
