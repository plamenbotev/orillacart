<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\PackageArchives;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Installs an extension from a zip of its files, a multipart upload in the "file" field: the .ocs scripts,
 * templates/….html.twig and .md files, at the zip's root or in one folder, with an optional extension.json (the
 * id, name, version and translations an export writes). A new extension is a draft; the files of one with the
 * same id are replaced, and it keeps running its old files until enabled again. Answers the extension and the
 * zip's files that are not an extension's, which were left out.
 */
#[Tool(unasked: 'an extension installed from a zip as a draft, or its files replaced as an import does; nothing runs until enabled, which asks')]
#[Route('/extensions/upload', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class UploadPackageArchiveAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== \UPLOAD_ERR_OK) {
            throw HttpException::invalidInput([['field' => 'file', 'message' => 'Choose a zip of the extension; it may be larger than the server accepts.']]);
        }
        if (($file->getSize() ?? 0) > PackageArchives::MAX_SIZE) {
            throw HttpException::invalidInput([['field' => 'file', 'message' => \sprintf('A zip is at most %d MB.', intdiv(PackageArchives::MAX_SIZE, 1024 * 1024))]]);
        }
        $temporary = tempnam(sys_get_temp_dir(), 'orillacart');
        if ($temporary === false) {
            throw new \RuntimeException('No temporary file for the upload.');
        }
        $file->moveTo($temporary);
        try {
            return $this->responder->ok($this->packages->installArchive($temporary), 201);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
