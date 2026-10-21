<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Http;

use OrillaCart\GiftCards\Application\Dto\IssueGiftCard;
use OrillaCart\GiftCards\Application\GiftCardService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A new gift card; the answer holds its code, shown this once. */
#[Route('/gift-cards', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class IssueGiftCardAction implements RequestHandlerInterface
{
    public function __construct(
        private GiftCardService $giftCards,
        private RequestMapper $input,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->giftCards->issue($this->input->body($request, IssueGiftCard::class), $this->authorization->userId()), 201);
    }
}
