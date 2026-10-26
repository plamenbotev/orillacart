<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Http;

use OrillaCart\Reviews\Application\Dto\UpdateReviewSettings;
use OrillaCart\Reviews\Application\Reviews;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Sets who may review products: no one, anyone, or only buyers. */
#[Route('/review-settings', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateReviewSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private Reviews $reviews,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->reviews->updateSettings($this->input->body($request, UpdateReviewSettings::class)));
    }
}
