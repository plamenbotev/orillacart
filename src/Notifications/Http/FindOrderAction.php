<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Http;

use OrillaCart\Notifications\Application\Dto\FindOrder;
use OrillaCart\Notifications\Application\OrderEmails;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A guest looks up an order by its number and email: the receipt link goes to the order's email, never to them.
 */
#[Route('/receipts/lookup', methods: ['POST'], capability: Route::PUBLIC, guard: 'order_lookup')]
final readonly class FindOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderEmails $emails,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $command = $this->input->body($request, FindOrder::class);
        $this->emails->findOrder($command->number, $command->email);

        return $this->responder->ok(['message' => 'If the order number and email match, the link to the order is on its way to that email.'], 202);
    }
}
