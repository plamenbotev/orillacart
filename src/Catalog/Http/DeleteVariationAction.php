<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Removes a variation from a product, so it is no longer sold. */
#[Route('/products/{id}/variations/{variation}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'variation' => '\d+'])]
final readonly class DeleteVariationAction implements RequestHandlerInterface
{
    public function __construct(
        private ProductService $products,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->products->removeVariation(PathParameter::id($request), PathParameter::id($request, 'variation'));

        return $this->responder->noContent();
    }
}
