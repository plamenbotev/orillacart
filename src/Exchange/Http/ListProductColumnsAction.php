<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Http;

use OrillaCart\Exchange\Application\ProductsExport;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The columns a products export can hold: the chosen ones in their order, then the others; nothing changes. */
#[Route('/exports/products/columns', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListProductColumnsAction implements RequestHandlerInterface
{
    public function __construct(
        private ProductsExport $export,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->export->columns());
    }
}
