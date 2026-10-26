<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Http;

use OrillaCart\Reports\Application\Dto\ReportQuery;
use OrillaCart\Reports\Application\Reports;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reports the products that sold best over a period, by what they brought or how many sold, against the period it is
 * compared with; nothing changes.
 */
#[Route('/reports/products', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowProductsReportAction implements RequestHandlerInterface
{
    public function __construct(
        private Reports $reports,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->reports->products($this->input->query($request, ReportQuery::class)));
    }
}
