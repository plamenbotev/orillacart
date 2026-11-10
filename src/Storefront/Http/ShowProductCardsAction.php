<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Http;

use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Storefront\Application\ProductCardsHtml;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The cards of published products, by id (?ids=12,30), as the shop's lists show them: for a visitor's wishlist
 * and the products they viewed, which their browser keeps, so the page around them can be cached. Priced in the
 * currency the shopper chose: in another than the shop's, no cache keeps them for others.
 */
#[Route('/product-cards', methods: ['GET'], capability: Route::PUBLIC)]
final readonly class ShowProductCardsAction implements RequestHandlerInterface
{
    public function __construct(
        private ProductCardsHtml $cards,
        private JsonResponder $responder,
        private Currencies $currencies,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ids = $request->getQueryParams()['ids'] ?? '';
        $ids = \is_string($ids) ? array_values(array_unique(array_filter(array_map(intval(...), explode(',', $ids)), static fn(int $id): bool => $id > 0))) : [];

        $response = $this->responder->ok($this->cards->render(\array_slice($ids, 0, ProductCardsHtml::MOST)));

        return $this->currencies->current() === $this->currencies->codes()[0] ? $response : $response->withHeader('Cache-Control', 'private, no-store');
    }
}
