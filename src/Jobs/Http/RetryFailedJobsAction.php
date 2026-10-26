<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Http;

use OrillaCart\Jobs\Application\Dto\ChooseFailedJobs;
use OrillaCart\Jobs\Application\JobOverview;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs failed background jobs again, chosen by id or all of them; answers with how many. Each does what it failed to
 * do, such as sending an email.
 */
#[Route('/jobs/failed/retry', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class RetryFailedJobsAction implements RequestHandlerInterface
{
    public function __construct(
        private JobOverview $jobs,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok(['retried' => $this->jobs->retry($this->input->body($request, ChooseFailedJobs::class))]);
    }
}
