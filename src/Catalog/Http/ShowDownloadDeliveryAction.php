<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\DownloadDelivery;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** How bought files are sent, by PHP or by the web server, and what the last test of it found; nothing changes. */
#[Route('/download-delivery', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowDownloadDeliveryAction implements RequestHandlerInterface
{
    public function __construct(
        private DownloadDelivery $delivery,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->delivery->current());
    }
}
