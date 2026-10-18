<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Http;

use OrillaCart\Coupons\Application\CouponService;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/coupons', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateCouponAction implements RequestHandlerInterface
{
    public function __construct(
        private CouponService $coupons,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->coupons->create($this->input->body($request, SaveCoupon::class)), 201);
    }
}
