<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

/**
 * What an extension's template printed, without what would run in the browser (design 73): read with WordPress's
 * HTML API as the browser will read it, and changed in place, so the rest of it reaches the page as written.
 *
 * Taken out: every `on…` attribute; an attribute that is a `javascript:`, `vbscript:` or `data:` URL (an image's
 * `data:image/…` stays); `srcdoc`; `<base>`'s address; `<meta http-equiv>`; `<object>`, `<embed>` and `<applet>`'s
 * sources; an SVG animation of an address or a handler. A `<script>` stays only as data (`application/json`,
 * `application/ld+json`), or as the extension's own file that `asset()` printed (an address it served this
 * request, `defer` and nothing else): any other becomes `text/plain`, which no browser runs, its `src` gone.
 *
 * Where the HTML API and a browser could read the same bytes differently, the whole piece is escaped instead: a
 * piece that ends inside a tag, a comment or a raw-text element (it would run into what the shop prints next), a
 * `<` inside a script, a style, a textarea or a title (inside an SVG, a browser reads tags there), `<noscript>`
 * (a browser with scripts on reads it as text) and `<plaintext>`. A template written to be shown never does these.
 */
final class ScriptFreeHtml
{
    /** Data a browser never runs: everything else in a script tag would. */
    private const array DATA = ['application/json', 'application/ld+json'];
    /** Elements whose text the HTML API skips over, which a browser may read as tags (inside SVG or MathML). */
    private const array RAW = ['SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'XMP', 'IFRAME', 'NOEMBED', 'NOFRAMES'];
    /** Elements that load or run a document from an attribute, whatever its address is. */
    private const array SOURCES = ['OBJECT' => ['data', 'codebase', 'classid', 'archive'], 'EMBED' => ['src', 'code'], 'APPLET' => ['code', 'codebase', 'archive', 'object'], 'BASE' => ['href', 'target']];
    /** SVG's animations, which can set an attribute to anything: an address or a handler. */
    private const array ANIMATIONS = ['ANIMATE', 'SET', 'ANIMATEMOTION', 'ANIMATETRANSFORM'];
    /** Where an image's `data:image/…` belongs. */
    private const array IMAGE_SOURCES = ['src', 'srcset', 'poster', 'href', 'xlink:href'];
    private const array IMAGES = ['IMG', 'SOURCE', 'VIDEO', 'INPUT', 'IMAGE'];

    /** @var array<string, true> the extensions' scripts asset() printed, by address: the scripts that run */
    private static array $served = [];

    /** A script asset() printed (ExtensionAssetsExtension): its tag is the one that stays as it is. */
    public static function allow(string $url): void
    {
        self::$served[$url] = true;
    }

    public static function clean(string $html): string
    {
        if ($html === '' || !str_contains($html, '<')) {
            return $html;
        }
        if (!class_exists(\WP_HTML_Tag_Processor::class)) {
            // Outside WordPress (a build, a unit test) nothing reaches a browser; inside it, the API is there (6.7).
            return \defined('ABSPATH') ? self::escaped($html) : $html;
        }
        $html = (string) preg_replace('/\x00/', "\u{FFFD}", $html);
        $processor = new \WP_HTML_Tag_Processor($html);
        while ($processor->next_token()) {
            $type = $processor->get_token_type();
            if ($type !== '#tag' || $processor->is_tag_closer()) {
                continue;
            }
            $tag = (string) $processor->get_tag();
            if ($tag === 'NOSCRIPT' || $tag === 'PLAINTEXT') {
                return self::escaped($html);
            }
            if (\in_array($tag, self::RAW, true) && str_contains($processor->get_modifiable_text(), '<')) {
                return self::escaped($html);
            }
            self::attributes($processor, $tag);
            if ($tag === 'SCRIPT') {
                if (self::served($processor)) {
                    continue;
                }
                $kind = $processor->get_attribute('type');
                if (!\is_string($kind) || !\in_array(strtolower(trim($kind)), self::DATA, true)) {
                    $processor->set_attribute('type', 'text/plain');
                }
                $processor->remove_attribute('src');
            }
        }
        if ($processor->paused_at_incomplete_token()) {
            return self::escaped($html);
        }

        return $processor->get_updated_html();
    }

    /** Whether the script is one asset() printed: the address it served, `defer`, nothing else, nothing inside. */
    private static function served(\WP_HTML_Tag_Processor $processor): bool
    {
        $src = $processor->get_attribute('src');
        $names = $processor->get_attribute_names_with_prefix('') ?? [];
        sort($names);

        return \is_string($src) && isset(self::$served[$src]) && $names === ['defer', 'src'] && $processor->get_modifiable_text() === '';
    }

    private static function attributes(\WP_HTML_Tag_Processor $processor, string $tag): void
    {
        foreach ($processor->get_attribute_names_with_prefix('') ?? [] as $name) {
            $value = $processor->get_attribute($name);
            if (self::refused($tag, $name, \is_string($value) ? $value : '')) {
                $processor->remove_attribute($name);
            }
        }
        if ($tag === 'META' && $processor->get_attribute('http-equiv') !== null) {
            $processor->remove_attribute('http-equiv');
            $processor->remove_attribute('content');
        }
    }

    private static function refused(string $tag, string $name, string $value): bool
    {
        if (str_starts_with($name, 'on') || $name === 'srcdoc') {
            return true;
        }
        if (\in_array($name, self::SOURCES[$tag] ?? [], true)) {
            return true;
        }
        if (\in_array($tag, self::ANIMATIONS, true) && $name === 'attributename') {
            $target = strtolower(trim($value));

            return str_contains($target, 'href') || str_starts_with($target, 'on');
        }

        return !self::plain($value) && !(\in_array($tag, self::IMAGES, true) && \in_array($name, self::IMAGE_SOURCES, true) && str_starts_with(self::address($value), 'data:image/'));
    }

    /** Whether a value is no address of a kind that runs or holds a document. */
    private static function plain(string $value): bool
    {
        return preg_match('/^(?:javascript|vbscript|livescript|data):/', self::address($value)) !== 1;
    }

    /** A value as a browser reads it for an address: no spaces or control characters, any case. */
    private static function address(string $value): string
    {
        return strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $value));
    }

    private static function escaped(string $html): string
    {
        return htmlspecialchars($html, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}
