<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures;

use OrillaCart\Shared\Lifecycle\Languages;

/**
 * A request in one language; what ran in which is remembered, null for the site's.
 */
final class FakeLanguages implements Languages
{
    /** @var list<?string> */
    public array $switched = [];

    public function __construct(public string $locale = 'en_US') {}

    public function current(): string
    {
        return $this->locale;
    }

    public function in(?string $locale, \Closure $work): mixed
    {
        $this->switched[] = $locale;

        return $work();
    }
}
