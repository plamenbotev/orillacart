<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Http;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Checkout\Application\Reorder;
use OrillaCart\Ordering\Application\ReceiptHandles;
use OrillaCart\Ordering\Http\PresentedReceipt;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Whoever holds the link to a receipt puts the order's items in their cart again.
 */
#[Route('/receipts/{receipt}/reorder', methods: ['POST'], capability: Route::PUBLIC, requirements: ['receipt' => ReceiptHandles::PATTERN], guard: 'receipts')]
final readonly class ReorderAction implements RequestHandlerInterface
{
    public function __construct(
        private PresentedReceipt $receipt,
        private Reorder $reorder,
        private CartCookie $cookie,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $receipt = $request->getAttribute('receipt');
        $view = $this->reorder->fromReceipt($this->cookie->owner($request), $this->receipt->of($request, \is_string($receipt) ? $receipt : ''));

        return $this->cookie->keep($this->responder->ok(['cart' => $view->result->cart, 'missed' => $view->missed]), $view->result, $request);
    }
}
