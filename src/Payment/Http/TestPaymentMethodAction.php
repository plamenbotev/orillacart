<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Http;

use OrillaCart\Payment\Application\Dto\TestPaymentSettings;
use OrillaCart\Payment\Application\PaymentMethodService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Tries a gateway's settings at the provider, as the form has them; nothing is saved. */
#[Route('/payment-methods/{gateway}/test', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['gateway' => '[a-z][a-z0-9_]{0,39}'], guard: 'settings_tests', changes: false)]
final readonly class TestPaymentMethodAction implements RequestHandlerInterface
{
    public function __construct(
        private PaymentMethodService $methods,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->methods->test(PathParameter::string($request, 'gateway'), $this->input->body($request, TestPaymentSettings::class)));
    }
}
