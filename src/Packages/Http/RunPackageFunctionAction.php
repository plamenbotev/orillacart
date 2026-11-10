<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\RunFunction;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The console: runs one top-level function of an extension with the given arguments; an enabled one runs the files
 * it was enabled with and its granted permissions, a draft or a disabled one its saved files with nothing granted.
 * Answers what it returned or how it failed, and what the run used. A function run with arguments of the caller's
 * choosing may mail customers, place orders or send their data to the extension's hosts, so the assistant asks.
 */
#[Route('/extensions/{id}/run', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
#[Tool(risk: Tool::CUSTOMERS)]
final readonly class RunPackageFunctionAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private RequestMapper $input, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->packages->run(ShowPackageAction::id($request), $this->input->body($request, RunFunction::class)));
    }
}
