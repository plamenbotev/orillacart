<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Application\TaxGroupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Changes a rate of a tax group. */
#[Route('/tax/groups/{id}/rates/{rate}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'rate' => '\d+'])]
final readonly class UpdateTaxRateAction implements RequestHandlerInterface
{
    public function __construct(
        private TaxGroupService $groups,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->groups->changeRate(PathParameter::id($request), PathParameter::id($request, 'rate'), $this->input->body($request, SaveTaxRate::class)));
    }
}
