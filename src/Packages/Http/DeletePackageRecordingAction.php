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

/** Removes one recording of an extension's run before its day is out. */
#[Tool(unasked: 'a debugger\'s recording removed, which would go within a day anyway')]
#[Route('/extensions/{id}/recordings/{recording}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*', 'recording' => '\d+'])]
final readonly class DeletePackageRecordingAction implements RequestHandlerInterface
{
    public function __construct(private PackageRecordings $recordings, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->recordings->delete(ShowPackageAction::id($request), PathParameter::id($request, 'recording'));

        return $this->responder->ok(['deleted' => true]);
    }
}
