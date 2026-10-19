<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Http;

use OrillaCart\Notifications\Application\Dto\SendReceiptLink;
use OrillaCart\Notifications\Application\OrderEmails;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A visitor with an expired receipt link asks for a new one: it goes to the order's email, never to them.
 */
#[Route('/receipts/link', methods: ['POST'], capability: Route::PUBLIC, guard: 'receipt_links')]
final readonly class SendReceiptLinkAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderEmails $emails,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->emails->receiptLink($this->input->body($request, SendReceiptLink::class)->receipt);

        return $this->responder->ok(['message' => 'If the link was ours, a new one is on its way to the order\'s email.'], 202);
    }
}
