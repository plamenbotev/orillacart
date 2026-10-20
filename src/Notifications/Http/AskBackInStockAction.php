<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Http;

use OrillaCart\Notifications\Application\BackInStock;
use OrillaCart\Notifications\Application\Dto\AskBackInStock;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Lifecycle\Languages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A visitor asks to hear when a sold-out item is back; answered alike whether or not they asked before. */
#[Route('/back-in-stock', methods: ['POST'], capability: Route::PUBLIC, guard: 'back_in_stock')]
final readonly class AskBackInStockAction implements RequestHandlerInterface
{
    public function __construct(
        private BackInStock $backInStock,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Languages $languages,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->backInStock->ask($this->input->body($request, AskBackInStock::class), $this->languages->current());

        return $this->responder->noContent();
    }
}
