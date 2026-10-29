<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\OpenSearch;

/**
 * OpenSearch's built-in language analyzers, by WordPress locale: the words of a document in German are also
 * kept as the german analyzer reads them (stemmed, its stopwords dropped), so "Hemden" finds "Hemd". A language
 * without one is searched as the shop's folded words only.
 */
final class OpenSearchLanguages
{
    /** The analyzers, by language code; a locale's own entry (pt_BR) before its language's. */
    private const array ANALYZERS = [
        'ar' => 'arabic', 'hy' => 'armenian', 'eu' => 'basque', 'bn' => 'bengali', 'pt_BR' => 'brazilian',
        'bg' => 'bulgarian', 'ca' => 'catalan', 'zh' => 'cjk', 'ja' => 'cjk', 'ko' => 'cjk', 'cs' => 'czech',
        'da' => 'danish', 'nl' => 'dutch', 'en' => 'english', 'et' => 'estonian', 'fi' => 'finnish', 'fr' => 'french',
        'gl' => 'galician', 'de' => 'german', 'el' => 'greek', 'hi' => 'hindi', 'hu' => 'hungarian',
        'id' => 'indonesian', 'ga' => 'irish', 'it' => 'italian', 'lv' => 'latvian', 'lt' => 'lithuanian',
        'nb' => 'norwegian', 'nn' => 'norwegian', 'no' => 'norwegian', 'fa' => 'persian', 'pt' => 'portuguese',
        'ro' => 'romanian', 'ru' => 'russian', 'ckb' => 'sorani', 'es' => 'spanish', 'sv' => 'swedish',
        'tr' => 'turkish', 'th' => 'thai',
    ];

    /** The analyzer of the locale's language, such as "german" for de_DE or de_CH_informal; null for none. */
    public static function analyzer(?string $locale): ?string
    {
        if ($locale === null || $locale === '') {
            return null;
        }
        $parts = explode('_', $locale);

        return self::ANALYZERS[$parts[0] . (isset($parts[1]) ? '_' . $parts[1] : '')] ?? self::ANALYZERS[$parts[0]] ?? null;
    }

    /**
     * @return list<string> every analyzer, each once
     */
    public static function all(): array
    {
        return array_values(array_unique(self::ANALYZERS));
    }
}
