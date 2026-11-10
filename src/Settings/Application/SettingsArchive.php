<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application;

/**
 * The shop's settings as one file, to keep or to take to another site: what each screen of settings saves, but
 * what only fits this site (its pages, its folders) and secrets (passwords, keys), which stay behind.
 */
interface SettingsArchive
{
    /**
     * @return array{format: string, version: string, exported_at: string, site: string, settings: array<string, mixed>}
     */
    public function export(): array;

    /**
     * Takes each section of the file the shop knows, as the file has it, but for secrets, which this site keeps.
     * A section the shop cannot read is left as it was. A secret is left behind too when the file changes the
     * address or user it goes to.
     *
     * @param array<array-key, mixed> $file
     * @param bool                    $administrator whether the site's administrator imports it, who alone points
     *                                               the shop at a new OpenSearch server
     *
     * @return array{imported: list<string>, refused: array<string, string>} the sections taken, and why the others were not
     */
    public function import(array $file, bool $administrator): array;
}
