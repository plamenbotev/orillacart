<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\DownloadService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The product stops offering a download file; buyers who have it keep their downloads. */
#[Route('/products/{id}/downloads/{file}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'file' => '\d+'])]
final readonly class RemoveDownloadAction implements RequestHandlerInterface
{
    public function __construct(
        private DownloadService $downloads,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->downloads->remove(PathParameter::id($request), PathParameter::id($request, 'file'));

        return $this->responder->noContent();
    }
}
