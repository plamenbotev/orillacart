<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\WriteFile;
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
 * Writes one file of an extension (a script ending in .ocs, or a template under templates/), making it when it is
 * not there. An enabled extension keeps running its old files until it is enabled again; the answer's findings are
 * the static check of the new ones. What is written reaches no one until a manager enables it, which asks again,
 * but it changes the extension's files, so the assistant asks.
 */
#[Route('/extensions/{id}/files/{path}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*', 'path' => '.+'])]
#[Tool(risk: Tool::CHANGES)]
final readonly class WritePackageFileAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private RequestMapper $input, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute('path');

        return $this->responder->ok($this->packages->writeFile(ShowPackageAction::id($request), \is_string($path) ? rawurldecode($path) : '', $this->input->body($request, WriteFile::class)->content));
    }
}
