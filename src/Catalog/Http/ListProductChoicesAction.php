<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\Dto\ProductChoiceQuery;
use OrillaCart\Catalog\Application\ProductChoices;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Products for a picker: ?search=words finds them by name in any language, ?include=12,40 names those given;
 * each by the product's own id, named in the admin's language. Anyone who edits pages picks products in blocks;
 * drafts, private and scheduled products are listed only to those who may edit others' products and read private
 * ones.
 */
#[Tool(leftOut: 'The admin\'s product pickers; find_products finds products, with more about each.')]
#[Route('/product-choices', methods: ['GET'], capability: 'edit_posts')]
final readonly class ListProductChoicesAction implements RequestHandlerInterface
{
    public function __construct(
        private ProductChoices $choices,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Authorization $authorization,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Products' edit_others_posts and read_private_posts are both the shop capability (ProductPostType).
        $unpublished = $this->authorization->isGranted(Capability::MANAGE_SHOP);

        return $this->responder->ok($this->choices->find($this->input->query($request, ProductChoiceQuery::class), !$unpublished));
    }
}
