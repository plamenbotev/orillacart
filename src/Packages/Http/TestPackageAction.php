<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\TestExtension;
use OrillaCart\Packages\Application\PackageTests;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Runs an extension's own tests, over its saved files or with `files` sent in the request laid over them (a path sent stands in for the saved one or is added, null removes one, the others stay); answers each test's outcome, message and place, the requests it made and the jobs it pushed. Changes nothing: what a test writes is rolled back, and with a read-only key every function that writes refuses. With `debug`, a test's name, that test is recorded for the debugger (design 71) and its result has the recording. */
#[Route('/extensions/{id}/test', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'], changes: false)]
final readonly class TestPackageAction implements RequestHandlerInterface
{
    public function __construct(private PackageTests $tests, private RequestMapper $input, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $command = $this->input->body($request, TestExtension::class);

        return $this->responder->ok(['tests' => $this->tests->run(ShowPackageAction::id($request), $command->files, $command->debug)]);
    }
}
