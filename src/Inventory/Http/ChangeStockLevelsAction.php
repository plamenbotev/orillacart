<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Http;

use OrillaCart\Inventory\Application\Dto\ChangeStockLevels;
use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Inventory\Application\StockChangedMeanwhile;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Saves the cells of the stock sheet that changed, all or none. Each cell gives a quantity with the quantity it was
 * loaded with (was, null when the stockroom had none), or a change to add (below zero to take). A level that moved
 * since it was loaded, as a sale moves it, refuses the whole save with 409 orillacart_stock_changed, naming each such
 * level as it is now.
 */
#[Route('/stock-levels', methods: ['PATCH'], capability: Capability::MANAGE_SHOP)]
final readonly class ChangeStockLevelsAction implements RequestHandlerInterface
{
    public function __construct(
        private StockService $stock,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Authorization $authorization,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->stock->changeLevels($this->input->body($request, ChangeStockLevels::class)->changes, new StockNote(userId: $this->authorization->userId()));
        } catch (StockChangedMeanwhile $e) {
            throw StockConflict::of($e);
        }

        return $this->responder->noContent();
    }
}
