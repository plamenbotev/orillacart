<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/products/{id}/variations/{variation}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'variation' => '\d+'])]
final readonly class UpdateVariationAction implements RequestHandlerInterface
{
    public function __construct(
        private ProductService $products,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->products->changeVariation(PathParameter::id($request), PathParameter::id($request, 'variation'), $this->input->body($request, SaveVariation::class)));
    }
}
