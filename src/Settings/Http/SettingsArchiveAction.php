<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Http;

use OrillaCart\Settings\Application\SettingsArchive;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The shop's settings as a file (GET /settings-archive), and a file of them taken in (PUT), section by section.
 */
#[Route('/settings-archive', methods: ['GET', 'PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class SettingsArchiveAction implements RequestHandlerInterface
{
    public function __construct(
        private SettingsArchive $archive,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'GET') {
            return $this->responder->ok($this->archive->export());
        }
        $file = json_decode((string) $request->getBody(), true);
        if (!\is_array($file)) {
            throw HttpException::badRequest('The file is not JSON.');
        }

        return $this->responder->ok($this->archive->import($file));
    }
}
