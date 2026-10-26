<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Http;

use OrillaCart\Jobs\Application\JobOverview;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Asks every running background worker to finish its batch and stop, so their supervisor starts them again; answers
 * with what the jobs are doing.
 */
#[Route('/jobs/workers/restart', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class RestartWorkersAction implements RequestHandlerInterface
{
    public function __construct(
        private JobOverview $jobs,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->jobs->restartWorkers();

        return $this->responder->ok($this->jobs->overview());
    }
}
