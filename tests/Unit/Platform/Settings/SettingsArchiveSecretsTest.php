<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Settings;

use OrillaCart\Platform\Settings\OptionSettingsArchive;
use PHPUnit\Framework\TestCase;

/**
 * A settings file holds no secret: each section declares its secrets and what says where they go, and an import
 * keeps this site's secret only while those stay as they are.
 */
final class SettingsArchiveSecretsTest extends TestCase
{
    private const array SEARCH = ['engine' => 'opensearch', 'host' => 'https://search.example', 'username' => 'shop', 'password' => 's3cret', 'index' => 'products'];

    public function testEverySectionDeclaresItsSecrets(): void
    {
        $archive = new \ReflectionClass(OptionSettingsArchive::class)->newInstanceWithoutConstructor();
        $sections = new \ReflectionMethod(OptionSettingsArchive::class, 'sections')->invoke($archive);
        \assert(\is_array($sections));

        self::assertSame(array_keys($sections), array_keys(OptionSettingsArchive::SECRETS));
    }

    public function testADeclaredSecretIsLeftOutWhateverItIsCalled(): void
    {
        $secrets = ['pin' => ['host']];

        self::assertSame(['host' => 'h'], OptionSettingsArchive::withoutSecrets($secrets, ['host' => 'h', 'pin' => '1234']));
        self::assertSame(['engine' => 'opensearch', 'host' => 'https://search.example', 'username' => 'shop', 'index' => 'products'], OptionSettingsArchive::withoutSecrets(OptionSettingsArchive::SECRETS['search'], self::SEARCH));
    }

    public function testASecretStaysUnlessWhatSaysWhereItGoesChanged(): void
    {
        $secrets = OptionSettingsArchive::SECRETS['search'];
        $file = ['password' => 'from-the-file', 'index' => 'catalog'] + self::SEARCH;

        $kept = OptionSettingsArchive::keepingSecrets($secrets, $file, self::SEARCH);
        self::assertSame('s3cret', $kept['password'], 'the same server and user: this site\'s password, never the file\'s');
        self::assertSame('catalog', $kept['index'], 'a setting that says nothing of where it goes changes nothing');

        foreach (['engine' => 'database', 'host' => 'https://elsewhere.example', 'username' => 'other'] as $key => $value) {
            self::assertArrayNotHasKey('password', OptionSettingsArchive::keepingSecrets($secrets, [$key => $value] + self::SEARCH, self::SEARCH), $key . ' changed: the password is forgotten');
        }
    }

    public function testASecretOfASectionThatDeclaresItIsForgottenEvenWhenNamedPlainly(): void
    {
        $kept = OptionSettingsArchive::keepingSecrets(['pin' => ['host']], ['host' => 'https://evil.example'], ['host' => 'https://good.example', 'pin' => '1234']);

        self::assertSame(['host' => 'https://evil.example'], $kept);
    }
}
