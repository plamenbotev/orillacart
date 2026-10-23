<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\Dto\ImportTaxRates;
use OrillaCart\Tax\Application\TaxRatesCsv;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds the rates of a CSV file (WooCommerce's columns) to a tax group, or puts them in place of its rates: the
 * group, as it is then. Every row is checked first; one that cannot be is named and nothing is saved.
 */
#[Route('/tax/groups/{id}/rates/csv', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ImportTaxRatesAction implements RequestHandlerInterface
{
    public function __construct(
        private TaxRatesCsv $csv,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->csv->import(PathParameter::id($request), $this->input->body($request, ImportTaxRates::class)));
    }
}
