<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\CaptureRuns;
use OrillaCart\Packages\Application\PackageRecordings;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records the extension's next runs as the shop makes them, for its debugger: `runs` of them (at most 20) within
 * `minutes` (at most 60), only the handlers whose name starts with `what` when given (`cart_rule age`, `on
 * order_paid`). The recordings hold what those runs saw, customers' data among it, and are kept a day.
 */
#[Tool(risk: Tool::CUSTOMERS)]
#[Route('/extensions/{id}/capture', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class CapturePackageRunsAction implements RequestHandlerInterface
{
    public function __construct(private PackageRecordings $recordings, private RequestMapper $input, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $command = $this->input->body($request, CaptureRuns::class);
        $what = $command->what === null || trim($command->what) === '' ? null : trim($command->what);

        return $this->responder->ok(['capture' => $this->recordings->capture(ShowPackageAction::id($request), $what, $command->runs, $command->minutes)]);
    }
}
