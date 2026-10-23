<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\Dto\SaveImportSchemes;
use OrillaCart\Tax\Application\ImportSchemes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Replaces the import schemes the shop sells under: those as they are then. */
#[Route('/tax/import-schemes', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateImportSchemesAction implements RequestHandlerInterface
{
    public function __construct(
        private ImportSchemes $schemes,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->schemes->save($this->input->body($request, SaveImportSchemes::class)));
    }
}
