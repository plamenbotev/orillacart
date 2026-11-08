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

/** Puts an extension's files back as a kept version had them; that becomes the newest version. An enabled extension keeps running what it was enabled with until enabled again. */
#[Route('/extensions/{id}/versions/{number}/restore', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*', 'number' => '\d+'])]
final readonly class RestorePackageVersionAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $number = $request->getAttribute('number');

        return $this->responder->ok($this->packages->restore(ShowPackageAction::id($request), \is_scalar($number) ? (int) $number : 0));
    }
}
