<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Notifications\Application\Site;
use OrillaCart\Shared\I18n\ContentLanguages;

/**
 * The site as emails link to it, in the language they are written in (design 51): the front page and a product's
 * page of that language, where the site has them.
 */
final readonly class WordPressSite implements Site
{
    public function __construct(
        private ContentLanguages $languages,
        private ProductTranslations $translations,
    ) {}

    public function name(): string
    {
        return wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    }

    public function adminEmail(): string
    {
        return get_bloginfo('admin_email');
    }

    public function url(): string
    {
        $language = $this->languages->multilingual() ? $this->languages->current() : null;

        return $language === null ? home_url('/') : $this->languages->homeUrl($language);
    }

    public function productUrl(int $productId): ?string
    {
        $url = get_permalink($this->translations->shown($productId));

        return \is_string($url) ? $url : null;
    }

    public function userEmail(int $userId): ?string
    {
        $user = get_userdata($userId);

        return $user instanceof \WP_User && $user->user_email !== '' ? $user->user_email : null;
    }
}
