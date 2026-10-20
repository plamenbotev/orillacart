<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Http;

use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/newsletter-settings', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowNewsletterSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private Newsletter $newsletter,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->newsletter->settings());
    }
}
