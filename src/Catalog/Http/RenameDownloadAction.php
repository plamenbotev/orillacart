<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\DownloadService;
use OrillaCart\Catalog\Application\Dto\RenameDownload;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/products/{id}/downloads/{file}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'file' => '\d+'])]
final readonly class RenameDownloadAction implements RequestHandlerInterface
{
    public function __construct(
        private DownloadService $downloads,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $command = $this->input->body($request, RenameDownload::class);

        return $this->responder->ok($this->downloads->rename(PathParameter::id($request), PathParameter::id($request, 'file'), $command->name));
    }
}
