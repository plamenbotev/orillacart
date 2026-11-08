<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Packages\Application\ExtensionFields;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** One product's shop details, with its attributes and variations; nothing changes. */
#[Route('/products/{id}', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ShowProductAction implements RequestHandlerInterface
{
    public function __construct(
        private ProductService $products,
        private ExtensionFields $fields,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = PathParameter::id($request);

        return $this->responder->ok($this->products->product($id)->withExtensions($this->fields->of('product', $id)));
    }
}
