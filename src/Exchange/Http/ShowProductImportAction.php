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

/**
 * How a product import stands: the file's columns it reads and ignores, how many rows are done, created, updated or
 * failed, and the first errors; nothing changes.
 */
#[Route('/imports/products/{id}', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ShowProductImportAction implements RequestHandlerInterface
{
    public function __construct(
        private ProductImports $imports,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->imports->import(PathParameter::id($request)));
    }
}
