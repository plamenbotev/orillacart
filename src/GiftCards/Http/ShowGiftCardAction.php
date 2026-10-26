<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Http;

use OrillaCart\GiftCards\Application\GiftCardService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** One gift card with every change of its balance; nothing changes. */
#[Route('/gift-cards/{id}', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ShowGiftCardAction implements RequestHandlerInterface
{
    public function __construct(
        private GiftCardService $giftCards,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->giftCards->card(PathParameter::id($request)));
    }
}
