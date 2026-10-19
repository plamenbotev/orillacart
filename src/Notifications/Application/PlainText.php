<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

/**
 * An email's HTML as plain text, for mail programs that do not show HTML: paragraphs, headings and table rows on
 * lines of their own, cells side by side, and each link's address after its text.
 */
final class PlainText
{
    public static function fromHtml(string $html): string
    {
        $text = preg_replace('#<(head|style|script|title)\b.*?</\1>#is', '', $html) ?? $html;
        // A link keeps where it goes, unless its text says it already.
        $text = preg_replace_callback(
            '#<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $link): string {
                $label = trim(strip_tags($link[3]));
                $url = html_entity_decode($link[2], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

                return $label === '' || $label === $url ? $url : "{$label} ({$url})";
            },
            $text,
        ) ?? $text;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(td|th)>#i', "\t", $text) ?? $text;
        $text = preg_replace('#</?(p|div|h[1-6]|tr|table|blockquote|ul|ol)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<li\b[^>]*>#i', "\n- ", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        $lines = [];
        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line);
            // One empty line between blocks, never more.
            if ($line !== '' || ($lines !== [] && end($lines) !== '')) {
                $lines[] = $line;
            }
        }

        return trim(implode("\n", $lines)) . "\n";
    }
}
