<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Http;

use OrillaCart\GiftCards\Application\GiftCardService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Which products the shop sells as gift cards, and for how many months a card stays valid; nothing changes. */
#[Route('/gift-cards/settings', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowGiftCardSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private GiftCardService $giftCards,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->giftCards->settings());
    }
}
