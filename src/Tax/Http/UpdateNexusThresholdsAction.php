<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\Dto\SaveNexusThresholds;
use OrillaCart\Tax\Application\NexusThresholdSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Saves the economic nexus thresholds: for every US state, and for those whose own differ. */
#[Route('/tax/nexus-thresholds', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateNexusThresholdsAction implements RequestHandlerInterface
{
    public function __construct(
        private NexusThresholdSettings $thresholds,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->thresholds->save($this->input->body($request, SaveNexusThresholds::class)));
    }
}
