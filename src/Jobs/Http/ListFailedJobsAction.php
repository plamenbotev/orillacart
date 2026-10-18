<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Http;

use OrillaCart\Jobs\Application\Dto\FailedJobQuery;
use OrillaCart\Jobs\Application\JobOverview;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/jobs/failed', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListFailedJobsAction implements RequestHandlerInterface
{
    public function __construct(
        private JobOverview $jobs,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->jobs->failed($this->input->query($request, FailedJobQuery::class)));
    }
}
