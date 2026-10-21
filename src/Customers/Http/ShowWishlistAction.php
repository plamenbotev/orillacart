<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\Wishlists;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The products the customer saved for later, newest first. */
#[Route('/account/wishlist', methods: ['GET'], capability: Capability::ACCOUNT)]
final readonly class ShowWishlistAction implements RequestHandlerInterface
{
    public function __construct(
        private Wishlists $wishlists,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->authorization->userId() ?? throw HttpException::unauthorized();

        return $this->responder->ok(['product_ids' => $this->wishlists->of($id)]);
    }
}
