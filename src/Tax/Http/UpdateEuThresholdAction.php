<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\Dto\SaveEuThreshold;
use OrillaCart\Tax\Application\TaxSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Changes the EU's threshold of distance sales the shop measures against, alone: the tax settings then. */
#[Route('/tax/eu-threshold', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateEuThresholdAction implements RequestHandlerInterface
{
    public function __construct(
        private TaxSettingsService $settings,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->settings->changeEuThreshold($this->input->body($request, SaveEuThreshold::class)));
    }
}
