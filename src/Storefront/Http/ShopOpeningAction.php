<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Http;

use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Storefront\Application\ShopOpening;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Whether visitors see "Opening soon" (GET); PUT opens the shop, DELETE shows "Opening soon" again. */
#[Route('/opening', methods: ['GET', 'PUT', 'DELETE'], capability: Capability::MANAGE_SHOP)]
final readonly class ShopOpeningAction implements RequestHandlerInterface
{
    public function __construct(
        private ShopOpening $opening,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        match ($request->getMethod()) {
            'PUT' => $this->opening->open(),
            'DELETE' => $this->opening->close(),
            default => null,
        };

        return $this->responder->ok(['opens_soon' => $this->opening->opensSoon()]);
    }
}
