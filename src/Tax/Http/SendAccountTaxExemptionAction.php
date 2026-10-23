<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\Dto\SendTaxExemption;
use OrillaCart\Tax\Application\ExemptionCertificates;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The signed-in customer sends a tax exemption certificate for the shop to check: multipart, with reason,
 * certificate, regions[] ("US-TX"), expires_on, and its copy in "file". The copy is never served to visitors.
 */
#[Route('/account/tax-exemptions', methods: ['POST'], capability: Capability::ACCOUNT, guard: 'tax_certificates')]
final readonly class SendAccountTaxExemptionAction implements RequestHandlerInterface
{
    public function __construct(
        private ExemptionCertificates $certificates,
        private RequestMapper $input,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->authorization->userId() ?? throw HttpException::unauthorized();
        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (\count($files) !== 1 || !$file instanceof UploadedFileInterface || $file->getError() !== \UPLOAD_ERR_OK) {
            throw HttpException::invalidInput([['field' => 'file', 'message' => \sprintf('Add a copy of the certificate, up to %d MB.', ExemptionCertificates::MAX_MEGABYTES)]]);
        }
        if (($file->getSize() ?? PHP_INT_MAX) > ExemptionCertificates::MAX_MEGABYTES * 1024 * 1024) {
            throw HttpException::invalidInput([['field' => 'file', 'message' => \sprintf('The file is larger than %d MB.', ExemptionCertificates::MAX_MEGABYTES)]]);
        }
        $fields = $this->input->map([
            'reason' => \is_string($body['reason'] ?? null) ? $body['reason'] : '',
            'certificate' => \is_string($body['certificate'] ?? null) ? $body['certificate'] : '',
            'regions' => \is_array($body['regions'] ?? null) ? array_values(array_filter($body['regions'], is_string(...))) : [],
            'expires_on' => \is_string($body['expires_on'] ?? null) && $body['expires_on'] !== '' ? $body['expires_on'] : null,
        ], SendTaxExemption::class);
        // Only once the fields hold is the file moved: a refused form leaves nothing behind.
        $temporary = tempnam(sys_get_temp_dir(), FormUploads::TEMPORARY_PREFIX);
        if ($temporary === false) {
            throw new \RuntimeException('No temporary file for the upload.');
        }
        $file->moveTo($temporary);

        return $this->responder->ok($this->certificates->send($id, $fields, $temporary, $file->getClientFilename() ?? 'certificate'), 201);
    }
}
