<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;

/**
 * Publishing a page or taking it down, as the page it is: its title, and whether its title or text changes too,
 * with the whole new text.
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
            // All of it: the owner says yes to what customers will read, not to its start.
            $lines[] = 'With this text, all of it:' . "\n" . self::text($arguments['content']);
        }

        return implode("\n", $lines);
    }

    /** The page's text as it reads: its blocks' markup gone, a paragraph per paragraph, a heading or a list item. */
    public static function text(string $content): string
    {
        // The source's own line breaks are not the page's: only its blocks break it.
        $flat = (string) preg_replace('/\s+/u', ' ', $content);
        $broken = (string) preg_replace('#</(p|h[1-6]|li|div|blockquote|tr|figcaption)>|<br\s*/?>|<!-- /?wp:[^>]*-->#i', "$0\n", $flat);
        $paragraphs = [];
        foreach (explode("\n", wp_strip_all_tags($broken)) as $line) {
            $line = trim((string) preg_replace('/[ \t\x{00a0}]+/u', ' ', html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($line !== '') {
                $paragraphs[] = $line;
            }
        }

        return implode("\n", $paragraphs);
    }
}
