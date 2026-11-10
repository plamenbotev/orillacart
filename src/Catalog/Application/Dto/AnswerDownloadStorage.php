<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Shared\Files\StorageCheck;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: say whose the downloads folder the shop no longer knows as its own is.
 */
final readonly class AnswerDownloadStorage
{
    public function __construct(
        /** adopt: the site moved and the folder is its own; new: the site is a copy and starts a folder of its own */
        #[Assert\Choice(choices: StorageCheck::ANSWERS)]
        public string $answer,
    ) {}
}
