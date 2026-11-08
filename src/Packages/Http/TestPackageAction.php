<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\PackageTests;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Runs an extension's own tests, over its saved files or over `files` sent in the request; answers each test's outcome, message and place, the requests it made and the jobs it pushed. Changes nothing: what a test writes is rolled back. */
#[Route('/extensions/{id}/test', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'], changes: false)]
final readonly class TestPackageAction implements RequestHandlerInterface
{
    public function __construct(private PackageTests $tests, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $files = null;
        if (\is_array($body) && \is_array($body['files'] ?? null)) {
            $files = [];
            foreach ($body['files'] as $path => $content) {
                if (\is_string($path) && \is_string($content)) {
                    $files[$path] = $content;
                }
            }
        }

        return $this->responder->ok(['tests' => $this->tests->run(ShowPackageAction::id($request), $files)]);
    }
}
