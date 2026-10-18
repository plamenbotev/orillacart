<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Http;

use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Storefront\Application\Dto\ChosenBundle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A bundle's price and stock with the customer's choices, for the product page while they choose: the price is
 * shown as the page shows prices. Changes nothing, so it is a POST only to carry the choices.
 */
#[Route('/products/{id}/bundle-quote', methods: ['POST'], capability: Route::PUBLIC, requirements: ['id' => '\d+'])]
final readonly class QuoteBundleAction implements RequestHandlerInterface
{
    public function __construct(
        private ShopCatalog $catalog,
        private Authorization $authorization,
        private TemplateRenderer $templates,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $chosen = $this->input->body($request, ChosenBundle::class);
        $offer = $this->catalog->bundleOffer(PathParameter::id($request), $chosen->bundleChoices, $this->authorization->userId());

        return $this->responder->ok([
            'price' => $offer->priceHidden ? null : $offer->price,
            'price_html' => $this->templates->render('storefront/_bundle_price.html.twig', ['offer' => $offer]),
            'for_sale' => $offer->forSale,
            'available' => $offer->available,
        ])->withHeader('Cache-Control', 'no-store, private');
    }
}
