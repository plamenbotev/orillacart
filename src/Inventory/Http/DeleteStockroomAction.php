<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Http;

use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Deletes a stockroom and the stock it holds; not while it is the only one holding some items, or owes items on
 * backorder.
 */
#[Route('/stockrooms/{id}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class DeleteStockroomAction implements RequestHandlerInterface
{
    public function __construct(
        private StockroomService $stockrooms,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->stockrooms->delete(PathParameter::id($request));

        return $this->responder->noContent();
    }
}
