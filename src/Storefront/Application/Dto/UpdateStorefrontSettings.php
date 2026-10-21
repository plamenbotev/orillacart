<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateStorefrontSettings
{
    public function __construct(
        public bool $structuredData = true,
        #[Assert\Choice(choices: ['auto', 'always', 'never'])]
        public string $sharingTags = 'auto',
        #[Assert\Choice(choices: ['guests', 'accounts', 'off'])]
        public string $wishlist = 'guests',
        public bool $recentlyViewed = true,
        public bool $dataLayer = false,
        public bool $hideSingleValueFilters = true,
    ) {}
}
