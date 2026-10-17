<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\DownloadService;
use OrillaCart\Catalog\Application\Dto\UploadDownload;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds a file to a product's downloads: a multipart upload with the file in the "file" field.
 */
#[Route('/products/{id}/downloads', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class UploadDownloadAction implements RequestHandlerInterface
{
    public function __construct(
        private DownloadService $downloads,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== \UPLOAD_ERR_OK) {
            throw HttpException::invalidInput([['field' => 'file', 'message' => 'Choose a file to upload; it may be larger than the server accepts.']]);
        }
        if (($file->getSize() ?? 0) > DownloadService::MAX_SIZE) {
            throw HttpException::invalidInput([['field' => 'file', 'message' => 'The file is larger than 1 GB.']]);
        }
        $temporary = tempnam(sys_get_temp_dir(), 'orillacart');
        if ($temporary === false) {
            throw new \RuntimeException('No temporary file for the upload.');
        }
        $file->moveTo($temporary);

        return $this->responder->ok($this->downloads->add(PathParameter::id($request), new UploadDownload($temporary, $file->getClientFilename() ?? 'download')), 201);
    }
}
