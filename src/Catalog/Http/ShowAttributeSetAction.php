<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\AttributeSetService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/attribute-sets/{id}', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ShowAttributeSetAction implements RequestHandlerInterface
{
    public function __construct(
        private AttributeSetService $sets,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->sets->set(PathParameter::id($request)));
    }
}
