<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Http;

use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * An order's receipt, for whoever holds its key: the customer gets it when ordering, so no account is needed.
 */
#[Route('/receipts/{key}', methods: ['GET'], capability: Route::PUBLIC, requirements: ['key' => '[a-f0-9]{32}'])]
final readonly class ShowReceiptAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderService $orders,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $key = $request->getAttribute('key');

        return $this->responder->ok($this->orders->byKey(\is_string($key) ? $key : ''));
    }
}
