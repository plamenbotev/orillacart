<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Http;

use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/stockrooms', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateStockroomAction implements RequestHandlerInterface
{
    public function __construct(
        private StockroomService $stockrooms,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->stockrooms->create($this->input->body($request, SaveStockroom::class)), 201);
    }
}
