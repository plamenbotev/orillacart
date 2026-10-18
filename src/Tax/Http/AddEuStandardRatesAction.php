<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\TaxGroupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fills a tax group with the standard VAT rate of every EU country it has none for: the group, as it is then.
 */
#[Route('/tax/groups/{id}/eu-standard-rates', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class AddEuStandardRatesAction implements RequestHandlerInterface
{
    public function __construct(
        private TaxGroupService $groups,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = PathParameter::id($request);
        $this->groups->addEuStandardRates($id);

        return $this->responder->ok($this->groups->group($id));
    }
}
