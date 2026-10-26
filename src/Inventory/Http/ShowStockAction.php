<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Http;

use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** How many of a product, variation or option each stockroom holds; nothing changes. */
#[Route('/stock/{kind}/{id}', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['kind' => 'product|variation|option', 'id' => '\d+'])]
final readonly class ShowStockAction implements RequestHandlerInterface
{
    public function __construct(
        private StockService $stock,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->stock->stock(new StockItemRef(strtolower(PathParameter::string($request, 'kind')), PathParameter::id($request))));
    }
}
