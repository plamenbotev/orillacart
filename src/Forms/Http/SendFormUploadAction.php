<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Http;

use OrillaCart\Forms\Application\Dto\SendUpload;
use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Forms\Application\TemporaryUploads;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\ClientAddress;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A shopper sends a file for a product's file field: multipart, with product_id, field and the file in "file".
 * The answer is the token it gives back; the file itself is never served to visitors.
 */
#[Route('/form-uploads', methods: ['POST'], capability: Route::PUBLIC, guard: 'form_uploads', changes: false)]
final readonly class SendFormUploadAction implements RequestHandlerInterface
{
    public function __construct(
        private FormUploads $uploads,
        private TemporaryUploads $temporaries,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $productId = \is_array($body) && \is_string($body['product_id'] ?? null) && ctype_digit($body['product_id']) ? (int) $body['product_id'] : 0;
        $field = \is_array($body) && \is_string($body['field'] ?? null) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $body['field']) === 1 ? $body['field'] : null;
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if ($productId < 1 || $field === null || \count($files) !== 1 || !$file instanceof UploadedFileInterface || $file->getError() !== \UPLOAD_ERR_OK) {
            throw HttpException::invalidInput([['field' => 'file', 'message' => \sprintf('Choose one file, up to %d MB.', FormUploads::MAX_MEGABYTES)]]);
        }
        if (($file->getSize() ?? PHP_INT_MAX) > FormUploads::MAX_MEGABYTES * 1024 * 1024) {
            throw HttpException::invalidInput([['field' => 'file', 'message' => \sprintf('The file is larger than %d MB.', FormUploads::MAX_MEGABYTES)]]);
        }
        $temporary = $this->temporaries->create();
        $file->moveTo($temporary);

        return $this->responder->ok($this->uploads->send(new SendUpload($productId, $field, $temporary, $file->getClientFilename() ?? 'file', ClientAddress::of($request->getServerParams()))), 201);
    }
}
