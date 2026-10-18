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

#[Route('/reports/coupons', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowCouponsReportAction implements RequestHandlerInterface
{
    public function __construct(
        private Reports $reports,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->reports->coupons($this->input->query($request, ReportQuery::class)));
    }
}
