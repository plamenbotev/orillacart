<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Http;

use OrillaCart\Coupons\Application\CouponService;
use OrillaCart\Coupons\Application\Dto\CouponQuery;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/coupons', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListCouponsAction implements RequestHandlerInterface
{
    public function __construct(
        private CouponService $coupons,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->coupons->coupons($this->input->query($request, CouponQuery::class)));
    }
}
