<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * The shop writes an email template in the admin: the editor's routes, the sandbox, previews and tests, and the
 * template's use once saved.
 */
final class EmailTemplatesRestTest extends RestTestCase
{
    private const string WELCOME = <<<'TWIG'
        {% extends 'emails/layout.html.twig' %}
        {% block subject %}Hello {{ first_name }}{% endblock %}
        {% block heading %}All set{% endblock %}
        {% block content %}<a href="{{ account_url }}">Your account</a>{% endblock %}
        TWIG;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testOnlyShopManagersEditTemplates(): void
    {
        wp_set_current_user(0);
        self::assertSame(401, $this->request('GET', '/email-templates')->get_status());
        $this->actAs('subscriber');
        self::assertSame(403, $this->request('GET', '/email-templates')->get_status());
    }

    public function testTheShopSavesPreviewsTestsAndResetsATemplate(): void
    {
        $user = $this->actAs('administrator');
        // WordPress tells the old address of a change, on a fresh site the first mail: not the one tested here.
        add_filter('send_email_change_email', '__return_false');
        wp_update_user(['ID' => $user, 'user_email' => 'owner@example.com']);
        remove_filter('send_email_change_email', '__return_false');

        $listed = array_column($this->rows($this->data($this->request('GET', '/email-templates'))), 'previews', 'name');
        self::assertSame('account_welcome', $listed['account-welcome'] ?? null);
        $file = $this->data($this->request('GET', '/email-templates/account-welcome'));
        self::assertFalse($file['edited']);
        self::assertSame($file['file_source'], $file['source']);
        self::assertSame(404, $this->request('GET', '/email-templates/nothing')->get_status());

        $refused = $this->request('PUT', '/email-templates/account-welcome', ['source' => "{{ constant('DB_PASSWORD') }}"], nonce: true);
        self::assertSame([422, 'orillacart_invalid_template'], [$refused->get_status(), $this->data($refused)['code']]);

        $preview = $this->request('POST', '/email-templates/account-welcome/preview', ['source' => self::WELCOME], nonce: true);
        self::assertSame(200, $preview->get_status(), (string) wp_json_encode($preview->get_data()));
        self::assertSame('Hello Ada', $this->data($preview)['subject']);
        $failing = $this->request('POST', '/email-templates/account-welcome/preview', ['source' => "{% extends 'emails/layout.html.twig' %}{% block subject %}{{ nickname }}{% endblock %}"], nonce: true);
        self::assertSame([422, 'orillacart_template_failed'], [$failing->get_status(), $this->data($failing)['code']]);

        $saved = $this->request('PUT', '/email-templates/account-welcome', ['source' => self::WELCOME], nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
        self::assertTrue($this->data($saved)['edited']);
        $tested = $this->request('POST', '/email-templates/account-welcome/test', [], nonce: true);
        self::assertSame(['to' => 'owner@example.com', 'sent' => true], $this->data($tested));
        self::assertSame(['owner@example.com', 'Test: Hello Ada'], [$this->mails[0]['to'], $this->mails[0]['subject']]);

        $reset = $this->data($this->request('DELETE', '/email-templates/account-welcome', nonce: true));
        self::assertFalse($reset['edited']);
        self::assertSame($file['source'], $reset['source']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> */
        return array_values($value);
    }

    private function clean(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_email_templates");
    }
}
