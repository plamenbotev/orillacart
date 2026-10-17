<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Http;

use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Application\PaymentMethodService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/payment-methods/{gateway}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['gateway' => '[a-z][a-z0-9_]{0,39}'])]
final readonly class UpdatePaymentMethodAction implements RequestHandlerInterface
{
    public function __construct(
        private PaymentMethodService $methods,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $gateway = $request->getAttribute('gateway');

        return $this->responder->ok($this->methods->update(\is_string($gateway) ? $gateway : '', $this->input->body($request, SavePaymentMethod::class)));
    }
}
