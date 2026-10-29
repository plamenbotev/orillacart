<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Doctrine;

/**
 * The names shoppers see for indexed values, as the database's tables keep them (they are filled whichever
 * engine searches): a term's name, an option as written, in the language shown where the index has it.
 */
final readonly class ValueLabels
{
    private const int CHUNK = 200;

    public function __construct(private SearchTables $tables) {}

    /**
     * @param list<array{string, string}> $pairs  facet and value
     * @param string|null                 $locale the language shown, on a site in several
     *
     * @return array<string, string> label by facet, "\x1F", value
     */
    public function of(array $pairs, ?string $locale = null): array
    {
        $label = $locale === null ? 'MIN(label)' : 'MIN(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(labels, ?)), label))';
        $labels = [];
        foreach (array_chunk($pairs, self::CHUNK) as $chunk) {
            $rows = $this->tables->connection()->fetchAllAssociative(
                \sprintf('SELECT facet, value, %s AS label FROM %s WHERE (facet, value) IN (%s) GROUP BY facet, value', $label, $this->tables->values(), implode(', ', array_fill(0, \count($chunk), '(?, ?)'))),
                [...($locale === null ? [] : ['$."' . $locale . '"']), ...array_merge(...$chunk)],
            );
            foreach ($rows as $row) {
                if (\is_string($row['facet']) && \is_string($row['value']) && \is_string($row['label'])) {
                    $labels[$row['facet'] . "\u{1F}" . $row['value']] = $row['label'];
                }
            }
        }

        return $labels;
    }
}
