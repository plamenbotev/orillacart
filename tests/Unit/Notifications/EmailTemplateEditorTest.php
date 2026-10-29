<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\LowStock;
use OrillaCart\Catalog\Application\StockItemNames;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Notifications\Application\Dto\EmailTemplateQuery;
use OrillaCart\Notifications\Application\Dto\EmailTemplateView;
use OrillaCart\Notifications\Application\Dto\PreviewEmail;
use OrillaCart\Notifications\Application\Dto\SaveEmailTemplate;
use OrillaCart\Notifications\Application\EmailNotWritten;
use OrillaCart\Notifications\Application\EmailTemplateEditor;
use OrillaCart\Notifications\Application\EmailTemplatePackages;
use OrillaCart\Notifications\Application\LowStockAlerts;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailRuleViolation;
use OrillaCart\Notifications\Domain\EmailTemplateNotFound;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Shared\View\TemplateRejected;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Notifications\InMemoryAlertedStock;
use PHPUnit\Framework\TestCase;

final class EmailTemplateEditorTest extends TestCase
{
    private const string SHIPPED = <<<'TWIG'
        {% extends 'emails/layout.html.twig' %}
        {% block subject %}Order {{ order.number }} left the workshop{% endblock %}
        {% block heading %}On its way, {{ order.billing.first_name }}{% endblock %}
        TWIG;

    private TestShop $shop;
    private EmailTemplateEditor $editor;
    private LowStockAlerts $low;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->low = $low = new LowStockAlerts(new LowStock($this->shop->productRecords, new StockItemNames($this->shop->productRecords, $this->shop->content), $this->shop->stock, $this->shop->stockrooms, $this->shop->settingsService), new InMemoryAlertedStock(), $this->shop->emailSettings, $this->shop->writer, $this->shop->mail, $this->shop->logger);
        $this->editor = new EmailTemplateEditor($this->shop->templateEditing, $this->shop->emailTemplateRecords, $this->shop->emails, $low, $this->shop->cartReminders, $this->shop->orders, $this->shop->writer, $this->shop->mail, $this->shop->site, $this->shop->clock, $this->shop->contentTranslations);
    }

    public function testEveryEmailFileIsListedWithTheEmailItShowsIn(): void
    {
        $listed = [];
        foreach ($this->editor->templates() as $template) {
            $listed[$template->name] = $template->previews;
        }

        self::assertSame('order_shipped', $listed['order-shipped']);
        self::assertSame('order_processing', $listed['layout'], 'the frame shows in the order confirmation');
        self::assertSame('order_processing', $listed['_order']);
        self::assertSame('account_confirm', $listed['account-confirm']);
        self::assertSame('low_stock', $listed['low-stock']);
        self::assertSame([], array_filter($this->editor->templates(), static fn(EmailTemplateView $template): bool => $template->edited));
    }

    public function testTheShopsOwnTemplateWritesTheEmailUntilItIsReset(): void
    {
        $order = $this->place();
        $file = $this->editor->template('order-shipped')->source;

        $saved = $this->editor->save('order-shipped', new SaveEmailTemplate(self::SHIPPED), 7);
        self::assertTrue($saved->edited);
        self::assertSame($file, $saved->fileSource, 'the file stays to go back to');
        $this->ship($order);
        self::assertSame("ada@example.com: Order {$order->number} left the workshop", $this->lastMail());
        self::assertStringContainsString('On its way, Ada', $this->shop->mail->emails[\count($this->shop->mail->emails) - 1]->html);

        self::assertSame($file, $this->editor->reset('order-shipped')->source);
        $this->shop->emails->send($order->number, EmailKind::OrderShipped);
        self::assertSame("ada@example.com: [Lamp Shop] Your order #{$order->number} is on its way", $this->lastMail());
    }

    public function testALanguagesReadersGetItsOwnTemplateAndOthersTheOneForEveryLanguage(): void
    {
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')];
        $this->editor->save('order-shipped', new SaveEmailTemplate(self::SHIPPED), 7);
        $german = $this->editor->save('order-shipped', new SaveEmailTemplate(str_replace(['left the workshop', 'On its way'], ['hat die Werkstatt verlassen', 'Unterwegs'], self::SHIPPED), 'de_DE'), 7);
        self::assertSame(['de_DE', true, self::SHIPPED], [$german->locale, $german->edited, $german->everyLanguage]);
        self::assertSame(['de_DE'], array_values(array_filter($this->editor->templates(), static fn(EmailTemplateView $template): bool => $template->name === 'order-shipped'))[0]->languages);

        $english = $this->place();
        $this->shop->languages->locale = 'de_DE';
        $deutsch = $this->place();
        $this->shop->languages->locale = 'en_US';
        $this->ship($english);
        self::assertSame("ada@example.com: Order {$english->number} left the workshop", $this->lastMail());
        $this->ship($deutsch);
        self::assertSame("ada@example.com: Order {$deutsch->number} hat die Werkstatt verlassen", $this->lastMail(), 'the order\'s language, whoever sends it');

        // A preview of the language's template is written in it; resetting it goes back to every language's.
        self::assertSame("Order {$english->number} hat die Werkstatt verlassen", $this->editor->preview('order-shipped', new PreviewEmail(order: $english->number, locale: 'de_DE'))->subject);
        $reset = $this->editor->reset('order-shipped', new EmailTemplateQuery('de_DE'));
        self::assertSame([false, self::SHIPPED], [$reset->edited, $reset->source]);
    }

    public function testAnEditedTemplateGoesToTheTranslatorsAndTheirsComesBackAsTheLanguagesTemplate(): void
    {
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch'), new Language('fr', 'fr_FR', 'Français')];
        $service = $this->shop->translationService;
        $service->active = true;
        $this->shop->packageSources->append(new EmailTemplatePackages($this->shop->emailTemplateRecords, $this->shop->templateEditing, $this->shop->clock));
        $editor = new EmailTemplateEditor($this->shop->templateEditing, $this->shop->emailTemplateRecords, $this->shop->emails, $this->low, $this->shop->cartReminders, $this->shop->orders, $this->shop->writer, $this->shop->mail, $this->shop->site, $this->shop->clock, $this->shop->contentTranslations, $this->shop->translationPackages);
        $id = EmailTemplatePackages::id('emails/order-shipped.html.twig');
        $key = "email_template:{$id}:source";

        $editor->save('order-shipped', new SaveEmailTemplate(self::SHIPPED), 7);
        self::assertSame([$key => self::SHIPPED], $service->words('email_template', $id), 'the template for every language is a package');
        $german = str_replace('left the workshop', 'hat die Werkstatt verlassen', self::SHIPPED);
        $editor->save('order-shipped', new SaveEmailTemplate($german, 'de_DE'), 7);
        self::assertSame([[$key, 'de_DE', $german]], $service->given, 'the German the shop wrote goes to the translators');

        $french = str_replace('left the workshop', 'a quitté l’atelier', self::SHIPPED);
        $service->finished[$key]['fr_FR'] = $french;
        self::assertSame([1, 0], $this->shop->translationPackages->pull());
        self::assertSame($french, $editor->template('order-shipped', new EmailTemplateQuery('fr_FR'))->source, 'their French is the French template');

        $service->finished[$key]['fr_FR'] = '{% block subject %}{{ order.number';
        self::assertSame([0, 1], $this->shop->translationPackages->pull(), 'a template that does not compile is refused');
        self::assertSame($french, $editor->template('order-shipped', new EmailTemplateQuery('fr_FR'))->source);

        $editor->reset('order-shipped');
        self::assertArrayNotHasKey("email_template:{$id}", $service->packages, 'back to the plugin\'s file: nothing to translate');
    }

    public function testATemplateForALanguageTheSiteHasNotIsRefused(): void
    {
        $this->expectException(EmailRuleViolation::class);

        $this->editor->save('order-shipped', new SaveEmailTemplate(self::SHIPPED, 'fr_FR'), 7);
    }

    public function testTheSandboxRefusesWhatReachesPastTheEmail(): void
    {
        foreach ([
            "{{ constant('DB_PASSWORD') }}" => 'constant',
            "{{ source('emails/layout.html.twig') }}" => 'source',
            '{% if %}' => 'at line 1',
            '{{ order|map(o => o)|first }}{{ [1]|sort((a, b) => a <=> b)|join }}{% sandbox %}{% endsandbox %}' => 'sandbox',
        ] as $source => $says) {
            try {
                $this->editor->save('order-shipped', new SaveEmailTemplate($source), 7);
                self::fail("saved: {$source}");
            } catch (TemplateRejected $e) {
                self::assertStringContainsString($says, $e->getMessage());
            }
        }
        self::assertFalse($this->editor->template('order-shipped')->edited);
    }

    public function testAPreviewShowsAnEditBeforeItIsSavedAndStrictly(): void
    {
        $order = $this->place();

        $preview = $this->editor->preview('order-shipped', new PreviewEmail(self::SHIPPED));
        self::assertSame(["Order {$order->number} left the workshop", 'ada@example.com'], [$preview->subject, $preview->to]);
        self::assertStringContainsString('On its way, Ada', $preview->text);
        self::assertFalse($this->editor->template('order-shipped')->edited, 'not saved');

        try {
            $this->editor->preview('order-shipped', new PreviewEmail("{% extends 'emails/layout.html.twig' %}{% block subject %}{{ order.nickname }}{% endblock %}"));
            self::fail('a variable the email is not given');
        } catch (EmailNotWritten $e) {
            self::assertStringContainsString('nickname', $e->getMessage());
        }
    }

    public function testTheFrameShowsInTheEmailAskedForAndAccountEmailsWithMadeUpDetails(): void
    {
        $order = $this->place();

        $frame = $this->editor->preview('layout', new PreviewEmail('<html><body>{% block heading %}{% endblock %}|{% block intro %}{% endblock %}|{% block content %}{% endblock %}</body></html>', 'order_completed', $order->number));
        self::assertStringStartsWith('<html><body>Your order is complete|', $frame->html);
        self::assertStringContainsString('Choose your password', $this->editor->preview('account-confirm', new PreviewEmail())->html);
        self::assertStringContainsString('Brass lamp', $this->editor->preview('low-stock', new PreviewEmail())->html, 'nothing is low: an item made up');
        self::assertStringContainsString('A note from the shop to the customer.', $this->editor->preview('customer-note', new PreviewEmail())->html);
    }

    public function testOrderEmailsNeedAnOrderToShowWith(): void
    {
        try {
            $this->editor->preview('order-shipped', new PreviewEmail());
            self::fail('no order');
        } catch (EmailRuleViolation $e) {
            self::assertSame('orillacart_no_order_to_preview', $e->errorCode);
        }
        try {
            $this->editor->template('wp-config');
            self::fail('not an email template');
        } catch (EmailTemplateNotFound) {
        }
    }

    public function testATestGoesToWhoeverAsks(): void
    {
        $this->place();

        $sent = $this->editor->sendTest('order-received', new PreviewEmail(), 7);

        self::assertTrue($sent->sent);
        self::assertSame('manager@shop.test', $sent->to);
        self::assertStringStartsWith('manager@shop.test: Test: [Lamp Shop] We received your order', $this->lastMail());
    }

    private function place(): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }

    private function ship(PlacedOrderView $order): void
    {
        $this->shop->orders->markPaid($order->number, null);
        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('shipped'), 1);
    }

    private function lastMail(): string
    {
        $mails = $this->shop->mail->summary();

        return $mails === [] ? '' : $mails[\count($mails) - 1];
    }
}
