<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\Dto\StockSheetQuery;
use OrillaCart\Catalog\Application\StockSheet;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A page of the stock sheet, by stockroom: products and their variations, or attribute options, with their levels to
 * count and correct; nothing changes.
 */
#[Route('/stock-sheet', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowStockSheetAction implements RequestHandlerInterface
{
    public function __construct(
        private StockSheet $sheet,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->sheet->sheet($this->input->query($request, StockSheetQuery::class)));
    }
}
