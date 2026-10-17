<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * The seeded countries and states, read through the public routes.
 */
final class GeographyRestTest extends RestTestCase
{
    public function testVisitorsListTheIsoCountries(): void
    {
        $response = $this->request('GET', '/countries');

        self::assertSame(200, $response->get_status());
        /** @var array<string, array{code: string, name: string, in_eu: bool, has_states: bool}> $countries */
        $countries = array_column($this->data($response), null, 'code');
        self::assertCount(249, $countries);
        self::assertSame(['code' => 'DE', 'name' => 'Germany', 'in_eu' => true, 'has_states' => false], $countries['DE']);
        self::assertSame(['code' => 'US', 'name' => 'United States', 'in_eu' => false, 'has_states' => true], $countries['US']);
        self::assertFalse($countries['GB']['in_eu']);
        self::assertCount(27, array_filter(array_column($countries, 'in_eu'), static fn(bool $inEu): bool => $inEu));
    }

    public function testVisitorsListTheStatesOfACountry(): void
    {
        $states = $this->data($this->request('GET', '/countries/US/states'));

        self::assertCount(51, $states);
        self::assertContains(['code' => 'CA', 'name' => 'California'], $states);
        self::assertSame(['code' => 'ES', 'name' => 'Espírito Santo'], array_column($this->data($this->request('GET', '/countries/BR/states')), null, 'code')['ES']);
        self::assertSame([], $this->data($this->request('GET', '/countries/DE/states')));
    }

    public function testUnknownCountriesAreNotFound(): void
    {
        self::assertSame(404, $this->request('GET', '/countries/XX/states')->get_status());
        self::assertCount(51, $this->data($this->request('GET', '/countries/us/states')), 'WordPress matches routes case-insensitively');
    }
}
