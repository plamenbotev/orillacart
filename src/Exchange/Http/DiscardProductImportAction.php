<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Http;

use OrillaCart\Exchange\Application\ProductImports;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Forgets a product import that is not running, and its uploaded file; products it already imported stay. */
#[Route('/imports/products/{id}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class DiscardProductImportAction implements RequestHandlerInterface
{
    public function __construct(
        private ProductImports $imports,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->imports->discard(PathParameter::id($request));

        return $this->responder->noContent();
    }
}
