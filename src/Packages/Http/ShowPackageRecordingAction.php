<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\PackageRecordings;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** One recording of an extension's run, whole: the files it ran, every step with its line and variables, the calls to the shop with their answers, and where it failed. */
#[Tool(leftOut: 'A whole recording is for a debugger to step through; the assistant reads one summed up with debug_package.')]
#[Route('/extensions/{id}/recordings/{recording}', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*', 'recording' => '\d+'])]
final readonly class ShowPackageRecordingAction implements RequestHandlerInterface
{
    public function __construct(private PackageRecordings $recordings, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->recordings->show(ShowPackageAction::id($request), PathParameter::id($request, 'recording')));
    }
}
