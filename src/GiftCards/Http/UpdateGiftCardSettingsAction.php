<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Http;

use OrillaCart\GiftCards\Application\Dto\GiftCardSettingsView;
use OrillaCart\GiftCards\Application\GiftCardService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/gift-cards/settings', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateGiftCardSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private GiftCardService $giftCards,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->giftCards->saveSettings($this->input->body($request, GiftCardSettingsView::class)));
    }
}
