<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Http;

use OrillaCart\Reports\Application\EuDistanceSales;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A shop in the EU against the EU's threshold of distance sales. */
#[Route('/reports/eu-distance-sales', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowEuSalesReportAction implements RequestHandlerInterface
{
    public function __construct(
        private EuDistanceSales $sales,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->sales->report());
    }
}
