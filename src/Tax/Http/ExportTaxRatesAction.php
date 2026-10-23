<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\TaxRatesCsv;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A tax group's rates as CSV, in WooCommerce's columns: {csv}, for the browser to save as a file.
 */
#[Route('/tax/groups/{id}/rates/csv', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ExportTaxRatesAction implements RequestHandlerInterface
{
    public function __construct(
        private TaxRatesCsv $csv,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok(['csv' => $this->csv->export(PathParameter::id($request))])->withHeader('Cache-Control', 'no-store, private');
    }
}
