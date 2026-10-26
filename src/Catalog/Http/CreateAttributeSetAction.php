<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\AttributeSetService;
use OrillaCart\Catalog\Application\Dto\SaveAttributeSet;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop adds an attribute set: a named list of attributes, with their options, that products can offer. */
#[Route('/attribute-sets', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateAttributeSetAction implements RequestHandlerInterface
{
    public function __construct(
        private AttributeSetService $sets,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->sets->create($this->input->body($request, SaveAttributeSet::class)), 201);
    }
}
