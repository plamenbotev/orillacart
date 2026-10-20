<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Checkout\Application\AddressChecks;
use OrillaCart\Checkout\Application\Dto\CheckAddresses;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What the add-ons' address checks say of the addresses on the checkout form, before the order is placed. Changes
 * nothing; guarded, as each call may cost the shop a lookup at the register.
 */
#[Route('/checkout/addresses', methods: ['POST'], capability: Route::PUBLIC, guard: 'address_check')]
final readonly class CheckAddressesAction implements RequestHandlerInterface
{
    public function __construct(
        private AddressChecks $checks,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->checks->suggest($this->input->body($request, CheckAddresses::class)));
    }
}
