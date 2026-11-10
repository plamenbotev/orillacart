<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Http;

use OrillaCart\Catalog\Application\DownloadService;
use OrillaCart\Shared\Files\StorageCheck;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Where download files are kept and whether the web server would hand them out by itself; ?fresh=1 tests again.
 */
#[Route('/download-storage', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowDownloadStorageAction implements RequestHandlerInterface
{
    public function __construct(
        private DownloadService $downloads,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $check = $this->downloads->storage(($request->getQueryParams()['fresh'] ?? '') === '1');

        return $this->responder->ok(self::body($check));
    }

    /**
     * claim: elsewhere or missing while the shop waits for the shop manager to say whose the folder is
     * (POST /download-storage/answer); null when it is the site's.
     *
     * @return array{location: string, directory: string, reachable: bool|null, nginx_rule: string, claim: string|null}
     */
    public static function body(StorageCheck $check): array
    {
        return ['location' => $check->location, 'directory' => $check->directory, 'reachable' => $check->reachable, 'nginx_rule' => $check->nginxRule, 'claim' => $check->claim];
    }
}
