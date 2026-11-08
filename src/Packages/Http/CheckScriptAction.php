<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Runs the static check over script files sent in the request, saved nowhere: an editor's check as its user types. Answers each finding with its file, line and column. */
#[Route('/script/check', methods: ['POST'], capability: Capability::MANAGE_SHOP, changes: false)]
final readonly class CheckScriptAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $files = [];
        foreach (\is_array($body) && \is_array($body['files'] ?? null) ? $body['files'] : [] as $path => $content) {
            if (\is_string($path) && \is_string($content)) {
                $files[$path] = $content;
            }
        }
        $scope = \is_array($body) && \is_string($body['id'] ?? null) && preg_match('/^[a-z][a-z0-9_]*$/', $body['id']) === 1 ? $body['id'] : 'draft';

        return $this->responder->ok(['findings' => $this->packages->checkFiles($files, $scope)]);
    }
}
