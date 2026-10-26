<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Http;

use OrillaCart\Coupons\Application\CouponService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Deletes a coupon; placed orders keep the code and amount they used. */
#[Route('/coupons/{id}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class DeleteCouponAction implements RequestHandlerInterface
{
    public function __construct(
        private CouponService $coupons,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->coupons->delete(PathParameter::id($request));

        return $this->responder->noContent();
    }
}
