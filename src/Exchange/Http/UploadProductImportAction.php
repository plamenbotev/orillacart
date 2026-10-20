<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Http;

use OrillaCart\Exchange\Application\ProductImports;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reads a products file, a multipart upload with the file in the "file" field, ready to start.
 */
#[Route('/imports/products', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class UploadProductImportAction implements RequestHandlerInterface
{
    private const int MAX_SIZE = 50 * 1024 * 1024;

    public function __construct(
        private ProductImports $imports,
        private JsonResponder $responder,
        private Authorization $authorization,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== \UPLOAD_ERR_OK) {
            throw HttpException::invalidInput([['field' => 'file', 'message' => 'Choose a CSV file; it may be larger than the server accepts.']]);
        }
        if (($file->getSize() ?? 0) > self::MAX_SIZE) {
            throw HttpException::invalidInput([['field' => 'file', 'message' => 'The file is larger than 50 MB; split it.']]);
        }
        $temporary = tempnam(sys_get_temp_dir(), 'orillacart');
        if ($temporary === false) {
            throw new \RuntimeException('No temporary file for the upload.');
        }
        $file->moveTo($temporary);
        try {
            return $this->responder->ok($this->imports->upload($temporary, $file->getClientFilename() ?? 'products.csv', $this->authorization->userId()), 201);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
