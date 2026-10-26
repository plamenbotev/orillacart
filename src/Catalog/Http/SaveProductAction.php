<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Saves a product's shop details all at once: price, sale, tax, stock, shipping, its own attributes and the attribute sets
 * it offers. Variations are saved on their own.
 */
#[Route('/products/{id}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class SaveProductAction implements RequestHandlerInterface
{
    public function __construct(
        private ProductService $products,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->products->save(PathParameter::id($request), $this->input->body($request, SaveProduct::class)));
    }
}
