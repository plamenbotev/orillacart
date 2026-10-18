<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Doctrine;

/**
 * Search words as InnoDB FULLTEXT can take them. InnoDB indexes words of three characters or more that are not
 * stopwords, and in boolean mode a required word it did not index matches nothing: "+tv" or "+the" empty the
 * results on MySQL and MariaDB alike. So the long words are required as prefixes ("+lamp*"), the shorter ones
 * must appear in the title, and stopwords are dropped. The tokens split where InnoDB splits: at anything that
 * is not a letter, digit or underscore.
 */
final readonly class FullTextQuery
{
    /** innodb_ft_min_token_size's default, which hosts rarely change. */
    private const int MIN_WORD_LENGTH = 3;

    /** INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD, the same on MySQL and MariaDB. */
    private const array STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is',
        'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will',
        'with', 'und', 'www',
    ];

    /**
     * @param string|null  $boolean    for MATCH … AGAINST (… IN BOOLEAN MODE); null without long words
     * @param list<string> $shortWords each must appear in the title
     */
    private function __construct(
        public ?string $boolean,
        public array $shortWords,
    ) {}

    public static function parse(string $text): self
    {
        $words = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $long = [];
        $short = [];
        foreach (array_unique($words === false ? [] : $words) as $word) {
            if (\in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            if (mb_strlen($word) >= self::MIN_WORD_LENGTH) {
                $long[] = '+' . $word . '*';
            } else {
                $short[] = $word;
            }
        }

        return new self($long === [] ? null : implode(' ', $long), $short);
    }
}
