<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Http;

use OrillaCart\Settings\Application\SampleData;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Whether the sample products are in the shop (GET), adds them (POST), or takes them away (DELETE). */
#[Route('/sample-data', methods: ['GET', 'POST', 'DELETE'], capability: Capability::MANAGE_SHOP)]
final readonly class SampleDataAction implements RequestHandlerInterface
{
    public function __construct(
        private SampleData $samples,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getMethod()) {
            'POST' => $this->responder->ok(['present' => true, 'added' => $this->samples->add()]),
            'DELETE' => $this->responder->ok(['present' => false, 'removed' => $this->samples->remove()]),
            default => $this->responder->ok(['present' => $this->samples->present()]),
        };
    }
}
