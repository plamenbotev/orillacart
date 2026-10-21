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

/** The customer saves a product for later, or takes it off their wishlist. */
#[Route('/account/wishlist/{product}', methods: ['PUT', 'DELETE'], capability: Capability::ACCOUNT, requirements: ['product' => '\d+'])]
final readonly class ChangeWishlistAction implements RequestHandlerInterface
{
    public function __construct(
        private Wishlists $wishlists,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->authorization->userId() ?? throw HttpException::unauthorized();
        $product = $request->getAttribute('product');
        $product = is_numeric($product) ? (int) $product : 0;

        return $this->responder->ok(['product_ids' => $request->getMethod() === 'DELETE' ? $this->wishlists->remove($id, $product) : $this->wishlists->add($id, $product)]);
    }
}
