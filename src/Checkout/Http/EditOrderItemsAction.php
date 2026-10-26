<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Checkout\Application\Dto\EditOrderItems;
use OrillaCart\Checkout\Application\OrderEditing;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Changes the items of an order on hold, and how it ships: the old items' stock is put back, the new ones are priced
 * and take stock. Its coupons are priced again, and one that no longer applies is dropped.
 */
#[Route('/orders/{id}/items', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class EditOrderItemsAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderEditing $editing,
        private RequestMapper $input,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->editing->edit(PathParameter::id($request), $this->input->body($request, EditOrderItems::class), $this->authorization->userId()));
    }
}
