<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Http;

use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Refunds;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives money back for an order: through the provider that took the payment, as store credit, or as a record of money
 * the shop paid back itself. Stock can be put back, and the customer is emailed.
 */
#[Tool(risk: Tool::MONEY)]
#[Route('/orders/{id}/refunds', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class RefundOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private Refunds $refunds,
        private RequestMapper $input,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->refunds->refund(PathParameter::id($request), $this->input->body($request, MakeRefund::class), $this->authorization->userId()), 201);
    }
}
