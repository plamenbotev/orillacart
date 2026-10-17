<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Http;

use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/stock/{kind}/{id}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['kind' => 'product|variation|option', 'id' => '\d+'])]
final readonly class UpdateStockAction implements RequestHandlerInterface
{
    public function __construct(
        private StockService $stock,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // WordPress matches routes case-insensitively.
        $item = new StockItemRef(strtolower(PathParameter::string($request, 'kind')), PathParameter::id($request));

        return $this->responder->ok($this->stock->setLevels($item, $this->input->body($request, SetStockLevels::class)));
    }
}
