<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Events;

use OrillaCart\Catalog\Domain\Event\DownloadRemoved;
use OrillaCart\Ordering\Application\Downloads;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class ForgetRemovedDownloads
{
    public function __construct(private Downloads $downloads) {}

    public function __invoke(DownloadRemoved $event): void
    {
        $this->downloads->forgetFile($event->storageKey);
    }
}
