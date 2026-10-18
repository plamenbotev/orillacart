<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\PriceRules;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/price-rules/{id}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class DeletePriceRuleAction implements RequestHandlerInterface
{
    public function __construct(
        private PriceRules $rules,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->rules->delete(PathParameter::id($request));

        return $this->responder->noContent();
    }
}
