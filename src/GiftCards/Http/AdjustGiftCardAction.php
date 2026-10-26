<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Http;

use OrillaCart\GiftCards\Application\Dto\AdjustBalance;
use OrillaCart\GiftCards\Application\GiftCardService;
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
 * Adds to a gift card's balance, or takes off it with a minus amount, with a note for the record; answers with the card
 * and every change of its balance.
 */
#[Tool(risk: Tool::MONEY)]
#[Route('/gift-cards/{id}/adjustments', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class AdjustGiftCardAction implements RequestHandlerInterface
{
    public function __construct(
        private GiftCardService $giftCards,
        private RequestMapper $input,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->giftCards->adjust(PathParameter::id($request), $this->input->body($request, AdjustBalance::class), $this->authorization->userId()));
    }
}
