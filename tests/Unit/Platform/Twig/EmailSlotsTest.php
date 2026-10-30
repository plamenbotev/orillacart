<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Twig;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Platform\Twig\EmailTwig;
use OrillaCart\Platform\Twig\ShopExtension;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use OrillaCart\Tests\Fixtures\Settings\InMemorySettingsRepository;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\Sandbox;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityPolicy;

/**
 * An email's template, which whoever edits emails writes in the admin, reaches only the email_ slots: any other
 * orillacart_ action could act (an order paid, a refund), not print.
 */
final class EmailSlotsTest extends TestCase
{
    public function testAnEmailReachesOnlyTheEmailSlots(): void
    {
        $twig = $this->environment(true);

        self::assertSame('', $twig->createTemplate("{{ slot('email_after_order', 7, false) }}")->render());
        try {
            $twig->createTemplate("{{ slot('order_paid', 7) }}")->render();
            self::fail('an email fired a shop action');
        } catch (SecurityError $e) {
            self::assertStringContainsString('email_', $e->getMessage());
        }
    }

    public function testTheStorefrontReachesEverySlot(): void
    {
        self::assertSame('', $this->environment(false)->createTemplate("{{ slot('product_after_form', 7) }}")->render());
    }

    private function environment(bool $email): Environment
    {
        $geography = new GeographyService(new InMemoryCountryRepository());
        $settings = new SettingsService(new InMemorySettingsRepository(new StoreSettings(new Currency('EUR'), null, WeightUnit::Kilogram, DimensionUnit::Centimetre)), new RecordingDispatcher(), $geography);
        $twig = new Environment(new ArrayLoader());
        $twig->addExtension(new ShopExtension($settings, $geography));
        if ($email) {
            $policy = new SecurityPolicy(EmailTwig::TAGS, EmailTwig::FILTERS, [], [], EmailTwig::FUNCTIONS, EmailTwig::TESTS);
            $policy->setStrict(true);
            new Sandbox($twig, $policy);
        }

        return $twig;
    }
}
