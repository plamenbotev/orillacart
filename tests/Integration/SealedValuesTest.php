<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\Security\SealedValues;
use OrillaCart\Platform\WordPress\Security\SiteSignatures;
use OrillaCart\Search\Infrastructure\WordPress\OptionEngineSettingsRepository;
use OrillaCart\Shared\Security\Secrets;
use OrillaCart\Shared\Security\Signatures;

/**
 * The doctor's re-sealing step on the wp-env site: what was sealed with the earlier key (the salt alone, before the
 * site's id took part) still opens, and --fix seals it again with the site's key; what no key opens is left alone.
 */
final class SealedValuesTest extends RestTestCase
{
    private mixed $engineOption = false;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_webhooks");
        $this->engineOption = get_option(OptionEngineSettingsRepository::OPTION);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_webhooks");
        if ($this->engineOption === false) {
            delete_option(OptionEngineSettingsRepository::OPTION);
        } else {
            update_option(OptionEngineSettingsRepository::OPTION, $this->engineOption, false);
        }
        parent::tearDown();
    }

    public function testWhatTheEarlierKeySealedOpensAndTheDoctorSealsItAgain(): void
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $table = "{$wpdb->prefix}orillacart_webhooks";
        $this->actAs('administrator');
        $hook = $this->data($this->request('POST', '/webhooks', ['name' => 'ERP', 'url' => 'https://erp.example.com/hook', 'topics' => ['product.changed']], nonce: true));
        self::assertIsString($hook['secret']);
        self::assertIsInt($hook['id']);
        $secrets = SiteSignatures::createSecrets();
        $earlier = new Secrets(new Signatures(SiteSignatures::earlierSecrets(wp_salt('auth'))[0]));
        $stranger = new Secrets(new Signatures('a salt this site never had'));

        // As the rows stood before 7d13ced: sealed with the salt alone. The shop reads it without a word of changed salts
        // (that is logged, and would be output here).
        $wpdb->update($table, ['secret' => $earlier->seal($hook['secret'])], ['id' => $hook['id']]);
        $shown = $this->data($this->request('GET', '/webhooks'));
        self::assertIsArray($shown['webhooks']);
        self::assertCount(1, $shown['webhooks']);
        // And one no key the site had opens.
        $wpdb->insert($table, ['name' => 'Lost', 'url' => 'https://lost.example.com/hook', 'topics' => '["product.changed"]', 'active' => 1, 'secret' => $stranger->seal('gone'), 'created_at' => '2026-10-01 00:00:00']);
        $lost = (int) $wpdb->insert_id;
        $storedBefore = $wpdb->get_var($wpdb->prepare('SELECT secret FROM %i WHERE id = %d', $table, $hook['id']));
        self::assertIsString($storedBefore);
        self::assertFalse($secrets->sealedWithCurrentKey($storedBefore));
        self::assertSame($hook['secret'], $secrets->open($storedBefore), 'the earlier key still opens it');
        // The search server's password, sealed the same way.
        update_option(OptionEngineSettingsRepository::OPTION, ['engine' => 'database', 'host' => 'https://search.example.com', 'username' => 'admin', 'password' => $earlier->seal('search-pass'), 'index' => 'orillacart', 'ready' => false], false);

        $sealed = self::kernel()->container()->get(SealedValues::class);
        self::assertInstanceOf(SealedValues::class, $sealed);

        self::assertSame(['stale' => 2, 'unreadable' => 1], $sealed->count(), 'without --fix: counted, nothing written');
        self::assertSame($storedBefore, $wpdb->get_var($wpdb->prepare('SELECT secret FROM %i WHERE id = %d', $table, $hook['id'])));

        self::assertSame(['resealed' => 2, 'unreadable' => 1], $sealed->reseal());

        $storedAfter = $wpdb->get_var($wpdb->prepare('SELECT secret FROM %i WHERE id = %d', $table, $hook['id']));
        self::assertIsString($storedAfter);
        self::assertTrue($secrets->sealedWithCurrentKey($storedAfter));
        self::assertSame($hook['secret'], $secrets->open($storedAfter));
        self::assertNull($earlier->open($storedAfter), 'the earlier key alone no longer opens it');
        $lostStored = $wpdb->get_var($wpdb->prepare('SELECT secret FROM %i WHERE id = %d', $table, $lost));
        self::assertIsString($lostStored);
        self::assertNull($secrets->open($lostStored), 'left as it was: no key opens it');
        $option = get_option(OptionEngineSettingsRepository::OPTION);
        self::assertIsArray($option);
        self::assertIsString($option['password']);
        self::assertTrue($secrets->sealedWithCurrentKey($option['password']));
        self::assertSame('search-pass', $secrets->open($option['password']));
        self::assertSame('https://search.example.com', $option['host']);
        self::assertSame(['stale' => 0, 'unreadable' => 1], $sealed->count(), 'nothing left but the unreadable one');

        // The shop reads the re-sealed secret as before, and silently (the unreadable one, listed, would say the salts changed).
        $wpdb->delete($table, ['id' => $lost]);
        $shown = $this->data($this->request('GET', '/webhooks'));
        self::assertIsArray($shown['webhooks']);
        self::assertCount(1, $shown['webhooks']);
    }
}
