<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Sitemaps;

/**
 * WordPress's sitemap renderer, which also writes the images of an entry that has them (Google's image sitemap
 * extension), as the products' entries do. Sitemaps without images come out as WordPress writes them.
 */
final class ImageSitemapRenderer extends \WP_Sitemaps_Renderer
{
    private const string IMAGE_NAMESPACE = 'http://www.google.com/schemas/sitemap-image/1.1';

    /**
     * @param array<mixed> $url_list
     *
     * @return string
     */
    public function get_sitemap_xml($url_list) // phpcs:ignore -- WordPress's name
    {
        $images = false;
        foreach ($url_list as $item) {
            $images = $images || (\is_array($item) && isset($item['images']));
        }
        if (!$images) {
            $xml = parent::get_sitemap_xml($url_list);

            return \is_string($xml) ? $xml : '';
        }
        $urlset = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" ?>' . $this->stylesheet . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="' . self::IMAGE_NAMESPACE . '" />');
        foreach ($url_list as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $url = $urlset->addChild('url');
            foreach ($item as $name => $value) {
                if ($name === 'loc' && \is_string($value)) {
                    $url->addChild('loc', esc_url($value));
                } elseif (\in_array($name, ['lastmod', 'changefreq', 'priority'], true) && \is_scalar($value)) {
                    $url->addChild($name, esc_xml((string) $value));
                } elseif ($name === 'images' && \is_array($value)) {
                    foreach ($value as $image) {
                        if (\is_string($image)) {
                            $url->addChild('image:image', null, self::IMAGE_NAMESPACE)->addChild('image:loc', esc_url($image), self::IMAGE_NAMESPACE);
                        }
                    }
                }
            }
        }
        $xml = $urlset->asXML();

        return \is_string($xml) ? $xml : '';
    }
}
