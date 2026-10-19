<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Ordering\Application\Dto\DownloadFile;
use OrillaCart\Ordering\Application\Dto\OrderDownloadView;
use OrillaCart\Ordering\Domain\DownloadGrant;
use OrillaCart\Ordering\Domain\DownloadGrantRepository;
use OrillaCart\Ordering\Domain\DownloadUnavailable;
use OrillaCart\Shared\Files\PrivateFiles;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Bought files: handing one out through its secret link, and the list a customer's account shows.
 */
final readonly class Downloads
{
    public function __construct(
        private DownloadGrantRepository $grants,
        private PrivateFiles $files,
        private OrderLinks $links,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * Counts a download and gives the file to send. A browser fetching the rest of one that broke off, soon after
     * it was counted, is given it without counting another, even when that was the last.
     *
     * @param bool $resuming the request asks for the file from past its start
     *
     * @throws DownloadUnavailable for an unknown or expired link, an unpaid order, no downloads left, or a lost file
     */
    public function open(string $token, bool $resuming = false): DownloadFile
    {
        $grant = preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $this->grants->findByToken($token) : null;
        if ($grant === null) {
            throw new DownloadUnavailable('This download link does not work.');
        }
        $now = $this->clock->now();
        $resumes = $resuming && $grant->resumes($now);
        $refusal = $resumes ? null : $grant->refusal($now);
        if ($refusal !== null) {
            throw new DownloadUnavailable($refusal);
        }
        $path = $this->files->path($grant->storageKey());
        if ($path === null) {
            $this->logger->error('The file of download {name} (order {order}) is missing from the store.', ['name' => $grant->name(), 'order' => $grant->order()->id()]);

            throw new DownloadUnavailable('The file is not available right now. Please contact the shop.');
        }
        if (!$resumes && !$this->grants->consume($grant, $now)) {
            throw new DownloadUnavailable('There are no downloads left for this file.');
        }

        return new DownloadFile($path, $grant->name(), $grant->mimeType(), $grant->storageKey());
    }

    /**
     * @return list<OrderDownloadView>
     */
    public function ofCustomer(int $customerId): array
    {
        return array_map(
            fn(DownloadGrant $grant): OrderDownloadView => OrderDownloadView::fromGrant($grant, ($grant->order()->lines()[$grant->linePosition()] ?? null)?->name() ?? '', $this->links->downloadUrl(...)),
            $this->grants->ofCustomer($customerId),
        );
    }

    /** Deletes the stored copy of a file no product offers any more, once no order needs it. */
    public function forgetFile(string $storageKey): void
    {
        if (!$this->grants->isStorageKeyUsed($storageKey)) {
            $this->files->delete($storageKey);
        }
    }
}
