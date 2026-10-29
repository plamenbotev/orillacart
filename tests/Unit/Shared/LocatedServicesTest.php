<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared;

use OrillaCart\Shared\Container\LocatedServices;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class LocatedServicesTest extends TestCase
{
    public function testALocatorThatDoesNotKnowItsClassesHasItsServicesAsked(): void
    {
        // As the uncompiled container builds them: "?" for every service's type.
        $locator = new ServiceLocator(['clock' => static fn() => new \DateTimeImmutable(), 'list' => static fn() => new \ArrayObject()]);
        self::assertSame(['clock' => '?', 'list' => '?'], $locator->getProvidedServices());

        self::assertSame(['clock' => \DateTimeImmutable::class, 'list' => \ArrayObject::class], LocatedServices::classes($locator));
    }
}
