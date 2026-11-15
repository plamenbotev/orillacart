<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Doctrine;

use PHPUnit\Framework\TestCase;

/**
 * What a request works on is what the database holds (PLAN 733). Doctrine keeps each entity it loaded and hands
 * the same copy back, so the copy is older than its row once another request changed it, or a statement of this
 * one went past it. Two rules keep that from deciding anything, and this test holds the code to them.
 */
final class FreshEntitiesTest extends TestCase
{
    /**
     * An aggregate read again under its lock (EntityManager::refresh()) is read whole: without "refresh" in the
     * cascade, the lines it loaded before stay as they were, and work under the lock decides on old lines.
     */
    public function testACollectionAnEntityOwnsIsReadAgainWithIt(): void
    {
        $missing = [];
        foreach (self::sources('/Domain/') as $path => $source) {
            preg_match_all('/#\[ORM\\\\OneToMany\((?<mapping>[^\]]*\])/', $source, $found);
            foreach ($found['mapping'] as $mapping) {
                if (str_contains($mapping, "'persist'") && !str_contains($mapping, "'refresh'")) {
                    $missing[] = $path . ': ' . $mapping;
                }
            }
        }

        self::assertSame([], $missing, 'a collection saved with its owner (cascade persist) is read again with it: add \'refresh\' to its cascade');
    }

    /**
     * A DQL UPDATE changes rows without their entities: the method that runs one reads the loaded ones again
     * (LoadedEntities::reread(), or EntityManager::refresh() of the one it was handed).
     */
    public function testAStatementThatUpdatesEntitiesIsFollowedByReadingThemAgain(): void
    {
        $stale = [];
        $statements = 0;
        foreach (self::sources('') as $path => $source) {
            // The file by its methods: each from its signature to the next one's.
            $methods = preg_split('/^    (?:public|private|protected)(?: static)? function /m', $source);
            self::assertIsArray($methods);
            foreach (\array_slice($methods, 1) as $method) {
                if (preg_match('/createQuery\(\s*[\'"]UPDATE |->update\(\s*[A-Za-z\\\\]+::class/', $method) !== 1) {
                    continue;
                }
                ++$statements;
                if (!str_contains($method, '->reread(') && !str_contains($method, '->refresh(')) {
                    $stale[] = $path . ': ' . strtok($method, '(') . '()';
                }
            }
        }

        self::assertGreaterThan(5, $statements, 'the test finds the statements it is about');
        self::assertSame([], $stale, 'after a DQL UPDATE, read the loaded entities again: $this->loaded->reread(TheEntity::class)');
    }

    /**
     * @return array<string, string> the plugin's PHP sources whose path holds the part, by path from src/
     */
    private static function sources(string $part): array
    {
        $root = \dirname(__DIR__, 4) . '/src';
        $sources = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php' && ($part === '' || str_contains($file->getPathname(), $part))) {
                $sources[substr($file->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($sources);

        return $sources;
    }
}
