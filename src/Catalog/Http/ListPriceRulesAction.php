<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\PriceRules;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop's price rules by name, with their prices, dates and who and what they are for; nothing changes. */
#[Route('/price-rules', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListPriceRulesAction implements RequestHandlerInterface
{
    public function __construct(
        private PriceRules $rules,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->rules->rules());
    }
}
