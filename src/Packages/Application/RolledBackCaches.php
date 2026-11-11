<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

/**
 * A test's writes are rolled back, but a cache outside the database keeps what they put in it: a product a test made,
 * an option it changed. Around each test, what the caches took is let go (design 69).
 */
interface RolledBackCaches
{
    /**
     * @template T
     *
     * @param callable(): T $work the test, whose transaction is rolled back before this returns
     *
     * @return T
     */
    public function around(callable $work): mixed;
}
