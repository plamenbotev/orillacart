<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\PackageRecordings;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Ends a live capture of the extension's runs before its runs or minutes are used up. */
#[Tool(unasked: 'a debugger\'s capture of an extension\'s runs ended early')]
#[Route('/extensions/{id}/capture', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class StopPackageCaptureAction implements RequestHandlerInterface
{
    public function __construct(private PackageRecordings $recordings, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->recordings->stopCapture(ShowPackageAction::id($request));

        return $this->responder->ok(['capture' => null]);
    }
}
