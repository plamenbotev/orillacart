<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shipping\Application\CarrierService;
use OrillaCart\Shipping\Application\Dto\TestShippingSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Tries a shipping method's settings at the courier, as the form has them; nothing is saved. */
#[Route('/shipping-methods/{method}/test', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['method' => '[a-z][a-z0-9_]{0,39}'], guard: 'settings_tests', changes: false)]
final readonly class TestShippingMethodAction implements RequestHandlerInterface
{
    public function __construct(
        private CarrierService $carriers,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->carriers->test(PathParameter::string($request, 'method'), $this->input->body($request, TestShippingSettings::class)));
    }
}
