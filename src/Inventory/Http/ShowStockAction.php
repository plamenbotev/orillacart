<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Http;

use OrillaCart\Catalog\Application\ProductTranslations;
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
        private ProductTranslations $translations,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->stock->stock($this->item(strtolower(PathParameter::string($request, 'kind')), PathParameter::id($request))));
    }

    /** A translation's post counts its original's stock (design 51): one product in every language. */
    private function item(string $kind, int $id): StockItemRef
    {
        return new StockItemRef($kind, $kind === 'product' ? $this->translations->original($id) : $id);
    }
}
