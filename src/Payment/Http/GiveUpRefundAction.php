<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Http;

use OrillaCart\Payment\Application\Refunds;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives up a refund the payment provider never confirmed, once the shop checked at the provider that no money went.
 * The order does not change.
 */
#[Tool(risk: Tool::MONEY)]
#[Route('/refunds/{id}/give-up', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class GiveUpRefundAction implements RequestHandlerInterface
{
    public function __construct(
        private Refunds $refunds,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->refunds->giveUp(PathParameter::id($request)));
    }
}
