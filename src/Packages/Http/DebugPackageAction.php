<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\DebugExtension;
use OrillaCart\Packages\Application\PackageRecordings;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Debugs an extension: runs one of its tests (`test`, by name, over the saved files or `files` sent) with a recording,
 * or opens a recording (`recording`, by id, from a debugged run or a live capture), and answers where it ended and
 * how it got there: the outcome and message, the place and the stack, the variables at the last line, the last
 * steps by file and line, and the calls to the shop with their answers. Changes nothing: a test's writes are rolled back.
 */
#[Route('/extensions/{id}/debug', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'], changes: false)]
final readonly class DebugPackageAction implements RequestHandlerInterface
{
    public function __construct(private PackageRecordings $recordings, private RequestMapper $input, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $command = $this->input->body($request, DebugExtension::class);

        return $this->responder->ok($this->recordings->debug(ShowPackageAction::id($request), $command->test, $command->recording, $command->files));
    }
}
