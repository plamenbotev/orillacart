<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\DownloadView;
use OrillaCart\Catalog\Application\Dto\UploadDownload;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Catalog\Domain\Event\DownloadRemoved;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Shared\Files\PrivateFiles;
use OrillaCart\Shared\Files\StorageCheck;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The files buyers of a product may download, kept in the shop's private file store.
 */
final readonly class DownloadService
{
    public const int MAX_SIZE = 1024 ** 3;

    public function __construct(
        private ProductRepository $products,
        private PrivateFiles $files,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * @throws ProductNotFound      also for a product whose commerce details were never saved
     * @throws CatalogRuleViolation for a file that cannot be stored
     */
    public function add(int $productId, UploadDownload $command): DownloadView
    {
        $product = $this->product($productId);
        try {
            $stored = $this->files->store($command->temporaryPath, $command->filename);
        } catch (\RuntimeException $e) {
            throw new CatalogRuleViolation('The file could not be stored. ' . $e->getMessage(), 'orillacart_download_not_stored', $e);
        }
        $file = $product->addDownload($stored->filename, $stored->key, $stored->mimeType, $stored->size);
        $this->products->save($product);

        return DownloadView::fromFile($file);
    }

    /**
     * @throws ProductNotFound
     * @throws CatalogRuleViolation for a file that is not the product's
     */
    public function rename(int $productId, int $fileId, string $name): DownloadView
    {
        $product = $this->product($productId);
        $file = $product->download($fileId);
        $file->rename($name);
        $this->products->save($product);

        return DownloadView::fromFile($file);
    }

    /**
     * The product stops offering the file; buyers who have it keep their downloads.
     *
     * @throws ProductNotFound
     * @throws CatalogRuleViolation for a file that is not the product's
     */
    public function remove(int $productId, int $fileId): void
    {
        $product = $this->product($productId);
        $file = $product->removeDownload($fileId);
        $this->products->save($product);
        $this->events->dispatch(new DownloadRemoved($file->storageKey()));
    }

    /** Where the files are kept, and whether the web server would hand them out by itself. */
    public function storage(bool $fresh = false): StorageCheck
    {
        return $this->files->check($fresh);
    }

    private function product(int $id): Product
    {
        return $this->products->find($id) ?? throw new ProductNotFound($id);
    }
}
