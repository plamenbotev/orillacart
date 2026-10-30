<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\ProductChangedMeanwhile;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Saves a product's shop details all at once: price, sale, tax, stock, shipping, its own attributes and the attribute sets
 * it offers. Variations are saved on their own. Send the updated_at the product was loaded with (null for one never
 * saved): a product saved since is refused with 409 orillacart_product_changed, the product as it is now in
 * data.product, and a save without it is refused the same way once the product was saved. The assistant may leave
 * updated_at out: its save then goes over whatever is there (the last save wins).
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
        $id = PathParameter::id($request);
        $asLoaded = $request->getAttribute(RouteDefinition::VIA) !== RouteDefinition::VIA_ASSISTANT;
        try {
            return $this->responder->ok($this->products->save($id, $this->input->body($request, SaveProduct::class), $asLoaded));
        } catch (ProductChangedMeanwhile $e) {
            throw new HttpException(409, $e->errorCode, $e->text, ['product' => $this->responder->normalized($this->products->product($id))], $e);
        }
    }
}
