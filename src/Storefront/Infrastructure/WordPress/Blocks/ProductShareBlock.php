<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * Ways to share the product's page, those the owner chooses (services): plain links to the networks and to an
 * email, which need no script and load nothing of the networks' until followed, and with the script, copying the
 * link and the browser's own share.
 */
final readonly class ProductShareBlock implements BlockDefinition
{
    /** What the block offers unless the owner chooses. */
    public const array SERVICES = ['copy', 'email', 'facebook', 'x', 'pinterest'];

    public function __construct(
        private ProductParts $parts,
        private ShopLinks $links,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/product-share';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);
        $offer = $id === null ? null : $this->parts->offer($id);
        $url = $id === null ? null : ($this->links->products([$id])[$id] ?? null);
        if ($id === null || $offer === null || $url === null) {
            return '';
        }
        $services = \is_array($attributes['services'] ?? null) ? array_values(array_filter($attributes['services'], \is_string(...))) : self::SERVICES;
        $image = get_the_post_thumbnail_url($id, 'large');
        $links = [];
        foreach ($services as $service) {
            $link = self::link($service, $url, $offer->name, \is_string($image) ? $image : null);
            if ($link !== null) {
                $links[] = ['service' => $service, 'label' => $link[0], 'url' => $link[1]];
            }
        }
        if ($links === [] && !\in_array('copy', $services, true)) {
            return '';
        }

        return $this->templates->render('storefront/_product_share.html.twig', [
            'id' => $id,
            'url' => $url,
            'title' => $offer->name,
            'links' => $links,
            'copy' => \in_array('copy', $services, true),
        ]);
    }

    /**
     * The service's name and its address that shares the page; null for one the block does not know (copy is a
     * button).
     *
     * @return array{string, string}|null
     */
    private static function link(string $service, string $url, string $title, ?string $image): ?array
    {
        $query = static fn(array $parameters): string => http_build_query(array_filter($parameters, static fn(mixed $value): bool => $value !== null), '', '&', PHP_QUERY_RFC3986);

        return match ($service) {
            'email' => [__('Email', 'orillacart'), 'mailto:?' . $query(['subject' => $title, 'body' => $url])],
            'facebook' => ['Facebook', 'https://www.facebook.com/sharer/sharer.php?' . $query(['u' => $url])],
            'x' => ['X', 'https://x.com/intent/post?' . $query(['url' => $url, 'text' => $title])],
            'pinterest' => ['Pinterest', 'https://www.pinterest.com/pin/create/button/?' . $query(['url' => $url, 'media' => $image, 'description' => $title])],
            'whatsapp' => ['WhatsApp', 'https://wa.me/?' . $query(['text' => "{$title} {$url}"])],
            'linkedin' => ['LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?' . $query(['url' => $url])],
            default => null,
        };
    }
}
