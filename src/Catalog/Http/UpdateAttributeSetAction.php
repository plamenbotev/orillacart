<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\AttributeSetService;
use OrillaCart\Catalog\Application\Dto\SaveAttributeSet;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Replaces an attribute set's name, state and whole list of attributes; it is refused when it would remove an option a
 * variation uses.
 */
#[Route('/attribute-sets/{id}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class UpdateAttributeSetAction implements RequestHandlerInterface
{
    public function __construct(
        private AttributeSetService $sets,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->sets->update(PathParameter::id($request), $this->input->body($request, SaveAttributeSet::class)));
    }
}
