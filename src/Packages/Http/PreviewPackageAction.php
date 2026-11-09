<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\PreviewExtension;
use OrillaCart\Packages\Application\PackagePreview;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Renders one of an enabled extension's views as the shop would show it (a page, an admin page, a slot, or a route answering HTML) and answers the HTML with the handler's data: for seeing a screen without a browser. The handler runs as a manager opening it would. */
#[Route('/extensions/{id}/preview', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class PreviewPackageAction implements RequestHandlerInterface
{
    public function __construct(private PackagePreview $preview, private RequestMapper $input, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->preview->render(PathParameter::string($request, 'id'), $this->input->body($request, PreviewExtension::class)));
    }
}
