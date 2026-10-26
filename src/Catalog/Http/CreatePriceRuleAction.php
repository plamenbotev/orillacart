<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\Dto\SavePriceRule;
use OrillaCart\Catalog\Application\PriceRules;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The shop adds a price rule: its own prices for customer groups, by quantity or between dates, for the products,
 * categories or brands it names. Placed orders keep their prices.
 */
#[Route('/price-rules', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreatePriceRuleAction implements RequestHandlerInterface
{
    public function __construct(
        private PriceRules $rules,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->rules->create($this->input->body($request, SavePriceRule::class)), 201);
    }
}
