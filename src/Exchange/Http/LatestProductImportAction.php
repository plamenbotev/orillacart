<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Http;

use OrillaCart\Exchange\Application\ProductImports;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The newest import, or null: the screen shows what came of it. */
#[Route('/imports/products/latest', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class LatestProductImportAction implements RequestHandlerInterface
{
    public function __construct(
        private ProductImports $imports,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok(['import' => $this->imports->latest()]);
    }
}
