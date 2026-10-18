<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Checkout\Application\OrderEditing;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\QueryStrings;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The offices or lockers a service (?shipping_option=carrier:5:office) delivers the order to, for changing how
 * it ships.
 */
#[Route('/orders/{id}/pickup-points', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ShowOrderPickupPointsAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderEditing $editing,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $option = QueryStrings::of($request)['shipping_option'] ?? '';

        return $this->responder->ok(preg_match(ShippingOptionView::ID_PATTERN, $option) === 1 ? $this->editing->pickupPoints(PathParameter::id($request), $option) : []);
    }
}
