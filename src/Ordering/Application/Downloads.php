<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Ordering\Application\Dto\DownloadFile;
use OrillaCart\Ordering\Application\Dto\OrderDownloadView;
use OrillaCart\Ordering\Domain\DownloadGrant;
use OrillaCart\Ordering\Domain\DownloadGrantRepository;
use OrillaCart\Ordering\Domain\DownloadUnavailable;
use OrillaCart\Shared\Files\ByteRange;
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
     * it was counted, is given it without counting another, even when that was the last: from about where it was
     * sent to (DownloadGrant::resumes()), so `bytes=1-` after a whole download is a download of its own.
     *
     * @param string $range  the Range header asked with, if any
     * @param string $client the address the request came from: only the one a download was counted for resumes it
     *
     * @throws DownloadUnavailable for an unknown or expired link, an unpaid order, no downloads left, or a lost file
     */
    public function open(string $token, string $range = '', string $client = ''): DownloadFile
    {
        $grant = preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $this->grants->findByToken($token) : null;
        if ($grant === null) {
            throw new DownloadUnavailable('This download link does not work.');
        }
        $now = $this->clock->now();
        $by = substr(hash('sha256', 'orillacart-download:' . $client), 0, 16);
        $path = $this->files->path($grant->storageKey());
        $size = $path === null ? 0 : max(0, (int) @filesize($path));
        $part = $range === '' ? null : ByteRange::of($range, $size);
        $from = $part instanceof ByteRange ? $part->start : 0;
        $until = $part instanceof ByteRange ? $part->end + 1 : $size;
        $resumes = ByteRange::resumes($range) && $grant->resumes($now, $by, $from, $size) && $this->grants->resume($grant, $by, $from + DownloadGrant::overlap($size), $until);
        $refusal = $resumes ? null : $grant->refusal($now);
        if ($refusal !== null) {
            throw new DownloadUnavailable($refusal);
        }
        if ($path === null) {
            $this->logger->error('The file of download {name} (order {order}) is missing from the store.', ['name' => $grant->name(), 'order' => $grant->order()->id()]);

            throw new DownloadUnavailable('The file is not available right now. Please contact the shop.');
        }
        if (!$resumes && !$this->grants->consume($grant, $now, $by, $until)) {
            throw new DownloadUnavailable('There are no downloads left for this file.');
        }

        return new DownloadFile($path, $grant->name(), $grant->mimeType(), $grant->storageKey());
    }

    /**
     * The download broke off: only this far into the file went out, so a resume may go on from there.
     */
    public function sentOnly(string $token, int $until): void
    {
        $grant = preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $this->grants->findByToken($token) : null;
        if ($grant !== null) {
            $this->grants->sentOnly($grant, $until);
        }
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
