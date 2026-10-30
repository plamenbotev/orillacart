<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Http;

use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Inventory\Application\Dto\ChangeStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Inventory\Application\StockChangedMeanwhile;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Changes how many of a product, variation or option the stockrooms listed hold; the others keep their levels. Each
 * level gives a quantity with the quantity it was loaded with (was, null when the stockroom had none; a quantity of
 * null removes the level), or a change to add (below zero to take). A level that moved since it was loaded, as a
 * sale moves it, refuses the save with 409 orillacart_stock_changed, naming each such level as it is now. When it
 * comes back in stock, customers who asked are emailed.
 */
#[Route('/stock/{kind}/{id}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['kind' => 'product|variation|option', 'id' => '\d+'])]
final readonly class UpdateStockAction implements RequestHandlerInterface
{
    public function __construct(
        private StockService $stock,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Authorization $authorization,
        private ProductTranslations $translations,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // WordPress matches routes case-insensitively.
        $item = $this->item(strtolower(PathParameter::string($request, 'kind')), PathParameter::id($request));

        $changes = array_map(
            static fn(SetStockLevel $level): ChangeStockLevel => new ChangeStockLevel($item->kind, $item->id, $level->stockroomId, $level->quantity, $level->was, $level->change),
            $this->input->body($request, SetStockLevels::class)->levels,
        );
        try {
            $this->stock->changeLevels($changes, new StockNote(userId: $this->authorization->userId()));
        } catch (StockChangedMeanwhile $e) {
            throw StockConflict::of($e);
        }

        return $this->responder->ok($this->stock->stock($item));
    }

    /** A translation's post counts its original's stock (design 51): one product in every language. */
    private function item(string $kind, int $id): StockItemRef
    {
        return new StockItemRef($kind, $kind === 'product' ? $this->translations->original($id) : $id);
    }
}
