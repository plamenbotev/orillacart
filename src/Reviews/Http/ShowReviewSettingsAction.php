<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Http;

use OrillaCart\Reviews\Application\Reviews;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/review-settings', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowReviewSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private Reviews $reviews,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->reviews->settings());
    }
}
