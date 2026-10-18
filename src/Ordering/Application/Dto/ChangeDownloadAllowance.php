<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: how many downloads are left of a bought file, and the last day it can be downloaded.
 */
final readonly class ChangeDownloadAllowance
{
    /**
     * @param int|null    $remaining null for no limit
     * @param string|null $expiresAt the last day (Y-m-d), in UTC; null for no end
     */
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?int $remaining = null,
        #[Assert\Date]
        public ?string $expiresAt = null,
    ) {}
}
