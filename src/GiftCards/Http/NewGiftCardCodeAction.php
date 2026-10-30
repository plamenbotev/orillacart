<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Http;

use OrillaCart\GiftCards\Application\GiftCardService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A new code for a gift card, sent to whom it is for; the old one pays no more. */
#[Tool(risk: Tool::CUSTOMERS)]
#[Route('/gift-cards/{id}/code', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class NewGiftCardCodeAction implements RequestHandlerInterface
{
    public function __construct(
        private GiftCardService $giftCards,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->giftCards->newCode(PathParameter::id($request), $this->authorization->userId()));
    }
}
