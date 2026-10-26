<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;

/**
 * Publishing a page or taking it down, as the page it is: its title, and whether its title or text changes too.
 */
final readonly class PagePublishDescription implements CallDescription
{
    public function describe(ToolDefinition $tool, array $arguments, Caller $caller): ?string
    {
        if ($tool->name !== 'publish_page') {
            return null;
        }
        $id = \is_int($arguments['id'] ?? null) ? $arguments['id'] : 0;
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== 'page') {
            return null;
        }
        $title = \is_string($arguments['title'] ?? null) ? $arguments['title'] : $post->post_title;
        $lines = [($arguments['publish'] ?? true) === false
            ? "Takes the page \"{$title}\" back to a draft: customers no longer see it."
            : "Puts the page \"{$title}\" live for customers (" . get_permalink($post) . ').'];
        if (\is_string($arguments['title'] ?? null) && $arguments['title'] !== $post->post_title) {
            $lines[] = "Its title changes from \"{$post->post_title}\".";
        }
        if (\is_string($arguments['content'] ?? null) && $arguments['content'] !== $post->post_content) {
            $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($arguments['content'])) ?? '');
            $lines[] = 'With this text:' . "\n" . (mb_strlen($text) > 1500 ? mb_substr($text, 0, 1500) . '…' : $text);
        }

        return implode("\n", $lines);
    }
}
