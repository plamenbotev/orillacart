<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\ImportSchemes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The import schemes there are for low-value goods, and those the shop sells under. */
#[Route('/tax/import-schemes', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowImportSchemesAction implements RequestHandlerInterface
{
    public function __construct(
        private ImportSchemes $schemes,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->schemes->current());
    }
}
