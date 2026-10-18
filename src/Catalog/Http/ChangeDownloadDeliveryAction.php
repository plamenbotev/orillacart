<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\DownloadDelivery;
use OrillaCart\Catalog\Application\Dto\ChangeDownloadDelivery;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests a way of sending downloads and keeps it when it works. Answers 200 either way, with the outcome and the
 * web server setting the method needs.
 */
#[Route('/download-delivery', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class ChangeDownloadDeliveryAction implements RequestHandlerInterface
{
    public function __construct(
        private DownloadDelivery $delivery,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->delivery->change($this->input->body($request, ChangeDownloadDelivery::class)));
    }
}
