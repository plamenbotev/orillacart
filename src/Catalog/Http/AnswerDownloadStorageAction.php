<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\DownloadService;
use OrillaCart\Catalog\Application\Dto\AnswerDownloadStorage;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Says whose a downloads folder the shop no longer knows as its own is, after the site was moved, restored or
 * copied: adopt (it moved; the files are served from it again) or new (a copy; it starts an empty folder of its
 * own and leaves the other alone). Answers with the folder as GET /download-storage shows it.
 */
#[Tool(risk: Tool::CHANGES)]
#[Route('/download-storage/answer', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class AnswerDownloadStorageAction implements RequestHandlerInterface
{
    public function __construct(
        private DownloadService $downloads,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok(ShowDownloadStorageAction::body($this->downloads->answerStorage($this->input->body($request, AnswerDownloadStorage::class)->answer)));
    }
}
